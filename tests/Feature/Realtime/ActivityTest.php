<?php

use App\Models\Proposal;
use App\Models\ProposalStatusChange;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('activity feed', function () {

    beforeEach(function () {
        $this->seed(RoleSeeder::class);
    });

    it('shows a speaker only their own proposals activity', function () {
        // The test for this endpoint: "everything the caller may see" is easy to
        // over-read as "everything", and a speaker reading another's submissions out of
        // the feed is a data leak that the response itself cannot reveal.
        $dana = User::factory()->speaker()->create();
        $rival = User::factory()->speaker()->create();

        $mine = Proposal::factory()->create(['user_id' => $dana->id]);
        $theirs = Proposal::factory()->create(['user_id' => $rival->id]);

        $response = $this->actingAs($dana)->getJson('/api/activity');

        $response->assertOk()->assertJsonCount(1, 'data');
        expect($response->json('data.0.proposal.id'))->toBe($mine->id)
            ->and(collect($response->json('data'))->pluck('proposal.id'))
            ->not->toContain($theirs->id);
    });

    it('shows reviewers and admins activity across all proposals', function () {
        $dana = User::factory()->speaker()->create();
        $rival = User::factory()->speaker()->create();
        Proposal::factory()->create(['user_id' => $dana->id]);
        Proposal::factory()->create(['user_id' => $rival->id]);

        foreach ([User::factory()->reviewer()->create(), User::factory()->admin()->create()] as $staff) {
            $this->actingAs($staff)->getJson('/api/activity')
                ->assertOk()
                ->assertJsonCount(2, 'data');
        }
    });

    it('carries the three durable event types', function () {
        $dana = User::factory()->speaker()->create();
        $maya = User::factory()->reviewer()->create();
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id]);

        Review::factory()->create(['proposal_id' => $proposal->id, 'user_id' => $maya->id]);
        ProposalStatusChange::create([
            'proposal_id' => $proposal->id,
            'from' => 'pending',
            'to' => 'approved',
            'changed_by' => $alex->id,
        ]);

        $response = $this->actingAs($dana)->getJson('/api/activity');

        $response->assertOk();
        expect(collect($response->json('data'))->pluck('type')->all())
            ->toEqualCanonicalizing(['proposal.created', 'review.created', 'proposal.status_changed']);
    });

    it('returns each row in the broadcast payload shape', function () {
        // Same shape as the events in app/Events, so one client
        // component renders a live push and a fetched row identically.
        $dana = User::factory()->speaker()->create(['name' => 'Dana Levy']);
        $proposal = Proposal::factory()->create(['user_id' => $dana->id]);

        $response = $this->actingAs($dana)->getJson('/api/activity');

        $response->assertOk();
        $row = $response->json('data.0');

        expect(array_keys($row))->toEqualCanonicalizing(['id', 'type', 'proposal', 'actor', 'occurred_at'])
            ->and(array_keys($row['proposal']))->toEqualCanonicalizing(['id', 'ref', 'title', 'status'])
            ->and(array_keys($row['actor']))->toEqualCanonicalizing(['id', 'name', 'initials'])
            ->and($row['actor']['name'])->toBe('Dana Levy')
            ->and($row['proposal']['id'])->toBe($proposal->id)
            // ISO 8601, matching what the events broadcast. These rows come off
            // the query builder with no casts, so the raw MySQL datetime is
            // what leaks out if nobody converts it.
            ->and($row['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
    });

    it('names the actor who did the thing, not the proposal author', function () {
        $dana = User::factory()->speaker()->create(['name' => 'Dana Levy']);
        $alex = User::factory()->admin()->create(['name' => 'Alex Rivera']);
        $proposal = Proposal::factory()->create(['user_id' => $dana->id]);

        ProposalStatusChange::create([
            'proposal_id' => $proposal->id,
            'from' => 'pending',
            'to' => 'approved',
            'changed_by' => $alex->id,
        ]);

        $response = $this->actingAs($alex)->getJson('/api/activity');

        expect($response->json('data.0.type'))->toBe('proposal.status_changed')
            ->and($response->json('data.0.actor.name'))->toBe('Alex Rivera');
    });

    it('orders newest first', function () {
        $maya = User::factory()->reviewer()->create();
        $old = Proposal::factory()->create(['created_at' => now()->subDays(3)]);
        $new = Proposal::factory()->create(['created_at' => now()->subMinute()]);

        $response = $this->actingAs($maya)->getJson('/api/activity');

        expect($response->json('data.0.proposal.id'))->toBe($new->id)
            ->and($response->json('data.1.proposal.id'))->toBe($old->id);
    });

    it('paginates', function () {
        $maya = User::factory()->reviewer()->create();
        Proposal::factory()->count(7)->create();

        $response = $this->actingAs($maya)->getJson('/api/activity?per_page=3');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.per_page', 3)
            ->assertJsonPath('meta.last_page', 3);
    });

    it('excludes activity on soft-deleted proposals', function () {
        // The feed shares the proposal list's visibility query, so the SoftDeletes
        // scope reaches it — a reappearing proposal republishes what deleting retracted.
        $maya = User::factory()->reviewer()->create();
        $kept = Proposal::factory()->create();
        $trashed = Proposal::factory()->create();
        Review::factory()->create(['proposal_id' => $trashed->id, 'user_id' => $maya->id]);

        $trashed->delete();

        $response = $this->actingAs($maya)->getJson('/api/activity');

        $response->assertOk()->assertJsonCount(1, 'data');
        expect($response->json('data.0.proposal.id'))->toBe($kept->id);
    });

    it('refuses an unauthenticated caller', function () {
        $this->getJson('/api/activity')->assertUnauthorized();
    });
});
