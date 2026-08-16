<?php

// tests/Feature/Admin/HistoryTest.php

use App\Models\Proposal;
use App\Models\ProposalStatusChange;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

describe('proposal status history', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('returns the audit trail newest first for an admin', function () {
        // Given — two rows with explicit, distinct created_at values. With a
        // single row (or two rows landing in the same second), any ordering —
        // or none at all — produces an identical response, so the ordering
        // claim is only meaningful with two rows pinned to different instants.
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        $earlier = ProposalStatusChange::create([
            'proposal_id' => $proposal->id, 'from' => 'pending', 'to' => 'rejected',
            'note' => 'First pass: needs more detail.', 'changed_by' => $alex->id,
        ]);
        $earlier->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $later = ProposalStatusChange::create([
            'proposal_id' => $proposal->id, 'from' => 'rejected', 'to' => 'approved',
            'note' => 'Strong fit.', 'changed_by' => $alex->id,
        ]);
        $later->forceFill(['created_at' => now()])->saveQuietly();

        // When
        $response = $this->actingAs($alex)->getJson("/api/proposals/{$proposal->id}/history");

        // Then — the later row (created "now") at index 0, the earlier row
        // (created yesterday) at index 1: newest first, by data, not by name.
        $response->assertOk()
            ->assertJsonPath('data.0.from', 'rejected')
            ->assertJsonPath('data.0.to', 'approved')
            ->assertJsonPath('data.0.note', 'Strong fit.')
            ->assertJsonPath('data.0.changed_by.name', $alex->name)
            ->assertJsonPath('data.1.from', 'pending')
            ->assertJsonPath('data.1.to', 'rejected')
            ->assertJsonPath('data.1.note', 'First pass: needs more detail.');
        expect($response->json('data.0.changed_at'))->not->toBeNull()
            ->and($response->json('data.0.changed_at'))->not->toBe($response->json('data.1.changed_at'));
    });

    it('orders by id as a tiebreaker, so a same-second tie is still deterministic', function () {
        // Given — two rows that could plausibly land in the same second.
        // proposal_status_changes has second-granularity created_at and no
        // updated_at (UPDATED_AT = null), so without an explicit id
        // tiebreaker two decisions in the same second have no deterministic
        // order. Asserting on the query itself, rather than the response
        // order, is deliberate: for a two-row table MySQL's incidental
        // tie-breaking can happen to match either order regardless of
        // whether ->latest('id') is present, which would let a
        // response-shape assertion pass either way.
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();
        ProposalStatusChange::create([
            'proposal_id' => $proposal->id, 'from' => 'pending', 'to' => 'rejected',
            'note' => 'First decision.', 'changed_by' => $alex->id,
        ]);
        ProposalStatusChange::create([
            'proposal_id' => $proposal->id, 'from' => 'rejected', 'to' => 'approved',
            'note' => 'Reconsidered a moment later.', 'changed_by' => $alex->id,
        ]);

        DB::enableQueryLog();

        // When
        $this->actingAs($alex)->getJson("/api/proposals/{$proposal->id}/history")->assertOk();

        // Then
        $query = collect(DB::getQueryLog())
            ->first(fn ($entry) => str_contains($entry['query'], 'proposal_status_changes'));
        DB::disableQueryLog();

        expect($query)->not->toBeNull()
            ->and($query['query'])->toContain('order by `created_at` desc, `id` desc');
    });

    it('refuses a reviewer and a speaker', function () {
        // Given
        $proposal = Proposal::factory()->create();

        // When / Then
        $this->actingAs(User::factory()->reviewer()->create())
            ->getJson("/api/proposals/{$proposal->id}/history")->assertForbidden();
        $this->actingAs(User::factory()->speaker()->create())
            ->getJson("/api/proposals/{$proposal->id}/history")->assertNotFound();
    });

    it('returns an empty list for a proposal that was never decided', function () {
        // Given
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        // When / Then
        $this->actingAs($alex)->getJson("/api/proposals/{$proposal->id}/history")
            ->assertOk()->assertJsonCount(0, 'data');
    });

    it('refuses an unauthenticated request', function () {
        // Given
        $proposal = Proposal::factory()->create();

        // When / Then
        $this->getJson("/api/proposals/{$proposal->id}/history")->assertUnauthorized();
    });
});
