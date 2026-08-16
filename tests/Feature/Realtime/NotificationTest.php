<?php

// tests/Feature/Realtime/NotificationTest.php

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
        // Given — the isolation test. A notification is addressed to one
        // person; the list must not be a shared feed.
        $dana = User::factory()->speaker()->create();
        $maya = User::factory()->reviewer()->create();
        $notify($dana);
        $notify($dana);
        $notify($maya);

        // When
        $response = $this->actingAs($dana)->getJson('/api/notifications');

        // Then
        $response->assertOk()->assertJsonCount(2, 'data');
    });

    it('filters to unread with unread_only', function () use ($notify) {
        // Given
        $dana = User::factory()->speaker()->create();
        $notify($dana);
        $notify($dana);
        $dana->notifications()->first()->markAsRead();

        // When
        $response = $this->actingAs($dana)->getJson('/api/notifications?unread_only=1');

        // Then
        $response->assertOk()->assertJsonCount(1, 'data');
    });

    it('returns the unread count in meta', function () use ($notify) {
        // Given
        $dana = User::factory()->speaker()->create();
        $notify($dana);
        $notify($dana);
        $notify($dana);
        $dana->notifications()->first()->markAsRead();

        // When
        $response = $this->actingAs($dana)->getJson('/api/notifications');

        // Then — the badge count is the UNREAD count, not the page total.
        $response->assertOk()
            ->assertJsonPath('meta.unread_count', 2)
            ->assertJsonPath('meta.total', 3);
    });

    it('returns each notification in the documented shape', function () use ($notify) {
        // Given
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id]);
        $notify($dana, $proposal);

        // When
        $response = $this->actingAs($dana)->getJson('/api/notifications');

        // Then
        $response->assertOk()
            ->assertJsonPath('data.0.type', 'proposal.status_changed')
            ->assertJsonPath('data.0.proposal_id', $proposal->id)
            ->assertJsonPath('data.0.read_at', null);

        expect(array_keys($response->json('data.0')))
            ->toEqualCanonicalizing(['id', 'type', 'title', 'body', 'proposal_id', 'read_at', 'created_at']);
    });

    it('marks one as read', function () use ($notify) {
        // Given
        $dana = User::factory()->speaker()->create();
        $notify($dana);
        $notify($dana);
        $id = $dana->notifications()->first()->id;

        // When
        $response = $this->actingAs($dana)->postJson("/api/notifications/{$id}/read");

        // Then
        $response->assertNoContent()->assertHeader('X-Unread-Count', '1');
        expect($dana->unreadNotifications()->count())->toBe(1);
    });

    it('refuses marking another user notification as read', function () use ($notify) {
        // Given
        $dana = User::factory()->speaker()->create();
        $maya = User::factory()->reviewer()->create();
        $notify($dana);
        $id = $dana->notifications()->first()->id;

        // When — maya reaches for dana's id
        $response = $this->actingAs($maya)->postJson("/api/notifications/{$id}/read");

        // Then — 404, not 403. This application never discloses that an id
        // exists to someone not entitled to it, and a notification id is a
        // uuid precisely so it cannot be guessed either.
        $response->assertNotFound();
        expect($dana->unreadNotifications()->count())->toBe(1);
    });

    it('marks all as read', function () use ($notify) {
        // Given
        $dana = User::factory()->speaker()->create();
        $maya = User::factory()->reviewer()->create();
        $notify($dana);
        $notify($dana);
        $notify($maya);

        // When
        $response = $this->actingAs($dana)->postJson('/api/notifications/read-all');

        // Then
        $response->assertNoContent()->assertHeader('X-Unread-Count', '0');
        expect($dana->unreadNotifications()->count())->toBe(0)
            // and it stops at the caller's own.
            ->and($maya->unreadNotifications()->count())->toBe(1);
    });

    it('refuses an unauthenticated caller on every route', function () {
        // Given / When / Then
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->postJson('/api/notifications/read-all')->assertUnauthorized();
        $this->postJson('/api/notifications/'.Str::uuid().'/read')->assertUnauthorized();
    });

    describe('addressing', function () {

        it('notifies reviewers and admins when a proposal is submitted, but not the author', function () {
            // Given
            $dana = User::factory()->speaker()->create();
            $maya = User::factory()->reviewer()->create();
            $alex = User::factory()->admin()->create();
            $other = User::factory()->speaker()->create();

            // When
            $this->actingAs($dana)->postJson('/api/proposals', [
                'title' => 'Type-safe APIs end to end',
                'description' => 'A talk about generating typed clients from an OpenAPI document.',
            ])->assertCreated();

            // Then
            expect($maya->notifications()->count())->toBe(1)
                ->and($alex->notifications()->count())->toBe(1)
                // Never notify someone about their own action.
                ->and($dana->notifications()->count())->toBe(0)
                // and a speaker is not on the reviewer list.
                ->and($other->notifications()->count())->toBe(0);
        });

        it('notifies the author when an admin decides, but not the admin', function () {
            // Given
            $dana = User::factory()->speaker()->create();
            $alex = User::factory()->admin()->create();
            $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

            // When
            $this->actingAs($alex)->patchJson("/api/proposals/{$proposal->id}/status", [
                'status' => 'approved',
            ])->assertOk();

            // Then
            expect($dana->notifications()->count())->toBe(1)
                ->and($dana->notifications()->first()->data['type'])->toBe('proposal.status_changed')
                ->and($alex->notifications()->count())->toBe(0);
        });

        it('notifies the author and admins when a review lands, but not the reviewer', function () {
            // Given
            $dana = User::factory()->speaker()->create();
            $maya = User::factory()->reviewer()->create();
            $alex = User::factory()->admin()->create();
            $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

            // When
            $this->actingAs($maya)->postJson("/api/proposals/{$proposal->id}/reviews", [
                'rating' => 4,
                'comment' => 'Strong idea, needs a sharper hook.',
            ])->assertCreated();

            // Then
            expect($dana->notifications()->count())->toBe(1)
                ->and($alex->notifications()->count())->toBe(1)
                ->and($maya->notifications()->count())->toBe(0);
        });

        it('never notifies the actor, even when they are on the recipient list', function () {
            // Given — ActivityNotifier directly, not the HTTP route. With
            // today's ProposalPolicy an actor is never inside their own
            // audience (only speakers create; the audiences are reviewers and
            // admins), so this overlap is unreachable through the API and the
            // filter is defensive code. Defensive code with no test is a
            // comment. Driving the service is the only way to prove it holds
            // for the day the policy widens.
            $alex = User::factory()->admin()->create();
            $maya = User::factory()->reviewer()->create();
            $proposal = Proposal::factory()->create(['user_id' => $alex->id]);

            // When — the admin is both the actor and a member of role.admin
            app(ActivityNotifier::class)->proposalCreated($proposal, $alex);

            // Then
            expect($alex->notifications()->count())->toBe(0)
                ->and($maya->notifications()->count())->toBe(1);
        });

        it('notifies once when someone is on two recipient lists', function () {
            // Given — review.created addresses the author AND every admin. An
            // admin's own proposal puts the same person on both lists, and
            // without the dedup they would be told twice about one review.
            $alex = User::factory()->admin()->create();
            $maya = User::factory()->reviewer()->create();
            $proposal = Proposal::factory()->create(['user_id' => $alex->id]);

            // When
            app(ActivityNotifier::class)->reviewCreated($proposal, $maya);

            // Then
            expect($alex->notifications()->count())->toBe(1);
        });

        it('does not notify on a no-op status change', function () {
            // Given — same status in, same status out. No audit row is written
            // and nothing happened, so nobody is told.
            $dana = User::factory()->speaker()->create();
            $alex = User::factory()->admin()->create();
            $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

            // When
            $this->actingAs($alex)->patchJson("/api/proposals/{$proposal->id}/status", [
                'status' => 'pending',
            ])->assertOk();

            // Then
            expect($dana->notifications()->count())->toBe(0);
        });

        it('does not notify on a review edit', function () {
            // Given — the endpoint is updateOrCreate and API.md has no
            // `review.updated`, so only the first one is an event.
            $dana = User::factory()->speaker()->create();
            $maya = User::factory()->reviewer()->create();
            $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

            $this->actingAs($maya)->postJson("/api/proposals/{$proposal->id}/reviews", [
                'rating' => 4, 'comment' => 'First pass.',
            ])->assertCreated();

            // When — same reviewer, same proposal, revised
            $this->actingAs($maya)->postJson("/api/proposals/{$proposal->id}/reviews", [
                'rating' => 5, 'comment' => 'Revised upward.',
            ])->assertCreated();

            // Then
            expect($dana->notifications()->count())->toBe(1);
        });
    });
});
