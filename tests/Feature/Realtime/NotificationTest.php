<?php

use App\Models\Proposal;
use App\Models\User;
use App\Notifications\ProposalStatusChangedNotification;
use App\Services\ActivityNotifier;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;

describe('notifications', function () {

    beforeEach(function () {
        $this->seed(RoleSeeder::class);
    });

    /** Persist one notification directly, so these tests measure the endpoint. */
    $notify = function (User $user, ?Proposal $proposal = null): void {
        $proposal ??= Proposal::factory()->create();
        $user->notify(new ProposalStatusChangedNotification($proposal, User::factory()->admin()->create()));
    };

    it('lists only the caller own notifications', function () use ($notify) {
        // The isolation test. A notification is addressed to one
        // person; the list must not be a shared feed.
        $dana = User::factory()->speaker()->create();
        $maya = User::factory()->reviewer()->create();
        $notify($dana);
        $notify($dana);
        $notify($maya);

        $response = $this->actingAs($dana)->getJson('/api/notifications');

        $response->assertOk()->assertJsonCount(2, 'data');
    });

    it('filters to unread with unread_only', function () use ($notify) {
        $dana = User::factory()->speaker()->create();
        $notify($dana);
        $notify($dana);
        $dana->notifications()->first()->markAsRead();

        $response = $this->actingAs($dana)->getJson('/api/notifications?unread_only=1');

        $response->assertOk()->assertJsonCount(1, 'data');
    });

    it('returns the unread count in meta', function () use ($notify) {
        $dana = User::factory()->speaker()->create();
        $notify($dana);
        $notify($dana);
        $notify($dana);
        $dana->notifications()->first()->markAsRead();

        $response = $this->actingAs($dana)->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonPath('meta.unread_count', 2)
            ->assertJsonPath('meta.total', 3);
    });

    it('returns each notification in the documented shape', function () use ($notify) {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id]);
        $notify($dana, $proposal);

        $response = $this->actingAs($dana)->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonPath('data.0.type', 'proposal.status_changed')
            ->assertJsonPath('data.0.proposal_id', $proposal->id)
            ->assertJsonPath('data.0.read_at', null);

        expect(array_keys($response->json('data.0')))
            ->toEqualCanonicalizing(['id', 'type', 'title', 'body', 'proposal_id', 'read_at', 'created_at']);
    });

    it('marks one as read', function () use ($notify) {
        $dana = User::factory()->speaker()->create();
        $notify($dana);
        $notify($dana);
        $id = $dana->notifications()->first()->id;

        $response = $this->actingAs($dana)->postJson("/api/notifications/{$id}/read");

        $response->assertNoContent()->assertHeader('X-Unread-Count', '1');
        expect($dana->unreadNotifications()->count())->toBe(1);
    });

    it('refuses marking another user notification as read', function () use ($notify) {
        $dana = User::factory()->speaker()->create();
        $maya = User::factory()->reviewer()->create();
        $notify($dana);
        $id = $dana->notifications()->first()->id;

        $response = $this->actingAs($maya)->postJson("/api/notifications/{$id}/read");

        // 404, not 403. This application never discloses that an id
        // exists to someone not entitled to it, and a notification id is a
        // uuid precisely so it cannot be guessed either.
        $response->assertNotFound();
        expect($dana->unreadNotifications()->count())->toBe(1);
    });

    it('marks all as read', function () use ($notify) {
        $dana = User::factory()->speaker()->create();
        $maya = User::factory()->reviewer()->create();
        $notify($dana);
        $notify($dana);
        $notify($maya);

        $response = $this->actingAs($dana)->postJson('/api/notifications/read-all');

        $response->assertNoContent()->assertHeader('X-Unread-Count', '0');
        expect($dana->unreadNotifications()->count())->toBe(0)
            // and it stops at the caller's own.
            ->and($maya->unreadNotifications()->count())->toBe(1);
    });

    it('refuses an unauthenticated caller on every route', function () {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->postJson('/api/notifications/read-all')->assertUnauthorized();
        $this->postJson('/api/notifications/'.Str::uuid().'/read')->assertUnauthorized();
    });

    describe('addressing', function () {

        it('notifies reviewers and admins when a proposal is submitted, but not the author', function () {
            $dana = User::factory()->speaker()->create();
            $maya = User::factory()->reviewer()->create();
            $alex = User::factory()->admin()->create();
            $other = User::factory()->speaker()->create();

            $this->actingAs($dana)->postJson('/api/proposals', [
                'title' => 'Type-safe APIs end to end',
                'description' => 'A talk about generating typed clients from an OpenAPI document.',
            ])->assertCreated();

            expect($maya->notifications()->count())->toBe(1)
                ->and($alex->notifications()->count())->toBe(1)
                // Never notify someone about their own action.
                ->and($dana->notifications()->count())->toBe(0)
                // and a speaker is not on the reviewer list.
                ->and($other->notifications()->count())->toBe(0);
        });

        it('notifies the author when an admin decides, but not the admin', function () {
            $dana = User::factory()->speaker()->create();
            $alex = User::factory()->admin()->create();
            $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

            $this->actingAs($alex)->patchJson("/api/proposals/{$proposal->id}/status", [
                'status' => 'approved',
            ])->assertOk();

            expect($dana->notifications()->count())->toBe(1)
                ->and($dana->notifications()->first()->data['type'])->toBe('proposal.status_changed')
                ->and($alex->notifications()->count())->toBe(0);
        });

        it('notifies the author and admins when a review lands, but not the reviewer', function () {
            $dana = User::factory()->speaker()->create();
            $maya = User::factory()->reviewer()->create();
            $alex = User::factory()->admin()->create();
            $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

            $this->actingAs($maya)->postJson("/api/proposals/{$proposal->id}/reviews", [
                'rating' => 4,
                'comment' => 'Strong idea, needs a sharper hook.',
            ])->assertCreated();

            expect($dana->notifications()->count())->toBe(1)
                ->and($alex->notifications()->count())->toBe(1)
                ->and($maya->notifications()->count())->toBe(0);
        });

        it('never notifies the actor, even when they are on the recipient list', function () {
            // Drives ActivityNotifier directly: under today's policy an actor is never
            // in their own audience, so the filter is defensive code the API cannot
            // reach. This proves it holds for the day the policy widens.
            $alex = User::factory()->admin()->create();
            $maya = User::factory()->reviewer()->create();
            $proposal = Proposal::factory()->create(['user_id' => $alex->id]);

            app(ActivityNotifier::class)->proposalCreated($proposal, $alex);

            expect($alex->notifications()->count())->toBe(0)
                ->and($maya->notifications()->count())->toBe(1);
        });

        it('notifies once when someone is on two recipient lists', function () {
            // review.created addresses the author AND every admin. An
            // admin's own proposal puts the same person on both lists, and
            // without the dedup they would be told twice about one review.
            $alex = User::factory()->admin()->create();
            $maya = User::factory()->reviewer()->create();
            $proposal = Proposal::factory()->create(['user_id' => $alex->id]);

            app(ActivityNotifier::class)->reviewCreated($proposal, $maya);

            expect($alex->notifications()->count())->toBe(1);
        });

        it('does not notify on a no-op status change', function () {
            // Same status in, same status out. No audit row is written
            // and nothing happened, so nobody is told.
            $dana = User::factory()->speaker()->create();
            $alex = User::factory()->admin()->create();
            $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

            $this->actingAs($alex)->patchJson("/api/proposals/{$proposal->id}/status", [
                'status' => 'pending',
            ])->assertOk();

            expect($dana->notifications()->count())->toBe(0);
        });

        it('does not notify on a review edit', function () {
            // The endpoint is updateOrCreate and API.md has no
            // `review.updated`, so only the first one is an event.
            $dana = User::factory()->speaker()->create();
            $maya = User::factory()->reviewer()->create();
            $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

            $this->actingAs($maya)->postJson("/api/proposals/{$proposal->id}/reviews", [
                'rating' => 4, 'comment' => 'First pass.',
            ])->assertCreated();

            $this->actingAs($maya)->postJson("/api/proposals/{$proposal->id}/reviews", [
                'rating' => 5, 'comment' => 'Revised upward.',
            ])->assertCreated();

            expect($dana->notifications()->count())->toBe(1);
        });
    });
});
