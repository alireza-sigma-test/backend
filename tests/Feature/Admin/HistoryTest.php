<?php

use App\Models\Proposal;
use App\Models\ProposalStatusChange;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

describe('proposal status history', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('returns the audit trail newest first for an admin', function () {
        // Distinct created_at values: with one row, or two in the same second, any
        // ordering produces an identical response and the claim proves nothing.
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

        $response = $this->actingAs($alex)->getJson("/api/proposals/{$proposal->id}/history");

        // The later row (created "now") at index 0, the earlier row
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
        // created_at is second-granularity, so same-second decisions need the id
        // tiebreaker. Asserted on the query rather than the response order: over two
        // rows MySQL's incidental ordering can match either way regardless.
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

        $this->actingAs($alex)->getJson("/api/proposals/{$proposal->id}/history")->assertOk();

        $query = collect(DB::getQueryLog())
            ->first(fn ($entry) => str_contains($entry['query'], 'proposal_status_changes'));
        DB::disableQueryLog();

        expect($query)->not->toBeNull()
            ->and($query['query'])->toContain('order by `created_at` desc, `id` desc');
    });

    it('refuses a reviewer and a speaker', function () {
        $proposal = Proposal::factory()->create();

        $this->actingAs(User::factory()->reviewer()->create())
            ->getJson("/api/proposals/{$proposal->id}/history")->assertForbidden();
        $this->actingAs(User::factory()->speaker()->create())
            ->getJson("/api/proposals/{$proposal->id}/history")->assertNotFound();
    });

    it('returns an empty list for a proposal that was never decided', function () {
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        $this->actingAs($alex)->getJson("/api/proposals/{$proposal->id}/history")
            ->assertOk()->assertJsonCount(0, 'data');
    });

    it('refuses an unauthenticated request', function () {
        $proposal = Proposal::factory()->create();

        $this->getJson("/api/proposals/{$proposal->id}/history")->assertUnauthorized();
    });
});
