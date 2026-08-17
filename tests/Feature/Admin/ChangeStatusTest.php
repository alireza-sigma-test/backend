<?php

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalStatusChange;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('admin status changes', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('approves a proposal and writes an audit row', function () {
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        $response = $this->actingAs($alex)->patchJson("/api/proposals/{$proposal->id}/status", [
            'status' => 'approved',
        ]);

        $response->assertOk()
            ->assertJsonPath('proposal.status', 'approved')
            ->assertJsonPath('changed_by.name', $alex->name);

        $audit = ProposalStatusChange::where('proposal_id', $proposal->id)->sole();

        expect($audit->from)->toBe(ProposalStatus::Pending)
            ->and($audit->to)->toBe(ProposalStatus::Approved)
            ->and($audit->changed_by)->toBe($alex->id);
    });

    it('stores the rejection note', function () {
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        $this->actingAs($alex)->patchJson("/api/proposals/{$proposal->id}/status", [
            'status' => 'rejected', 'note' => 'Overlaps with an accepted talk.',
        ])->assertOk();

        expect(ProposalStatusChange::sole()->note)->toBe('Overlaps with an accepted talk.');
    });

    it('reports changed_at from the audit row, not a recomputed clock', function () {
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        $response = $this->actingAs($alex)->patchJson("/api/proposals/{$proposal->id}/status", [
            'status' => 'approved',
        ]);

        $response->assertOk();
        expect($response->json('changed_at'))
            ->toBe(ProposalStatusChange::sole()->created_at->toIso8601String());
    });

    it('records the true prior status when it was not pending', function () {
        // Every other test starts from pending, so `from` could be
        // hardcoded and still pass. This one starts from approved.
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->approved()->create();

        $this->actingAs($alex)->patchJson("/api/proposals/{$proposal->id}/status", [
            'status' => 'rejected',
        ])->assertOk();

        $audit = ProposalStatusChange::sole();
        expect($audit->from)->toBe(ProposalStatus::Approved)
            ->and($audit->to)->toBe(ProposalStatus::Rejected);
    });

    it('rejects a request with no status at all', function () {
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        $this->actingAs($alex)->patchJson("/api/proposals/{$proposal->id}/status", [])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    });

    it('refuses a status change from a reviewer', function () {
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();

        $this->actingAs($maya)->patchJson("/api/proposals/{$proposal->id}/status", ['status' => 'approved'])
            ->assertForbidden();

        expect(ProposalStatusChange::count())->toBe(0);
    });

    it('refuses a status change from the owning speaker', function () {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->for($dana, 'author')->create();

        $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}/status", ['status' => 'approved'])
            ->assertForbidden();
    });

    it('returns 404 for another speakers proposal instead of 403, so existence is not disclosed', function () {
        // A speaker can never view this proposal, so the route must
        // 404 before the changeStatus policy ever runs, matching ProposalController::show.
        $dana = User::factory()->speaker()->create();
        $theirs = Proposal::factory()->create();

        $this->actingAs($dana)->patchJson("/api/proposals/{$theirs->id}/status", ['status' => 'approved'])
            ->assertNotFound();
    });

    it('rejects an unknown status value', function () {
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        $this->actingAs($alex)->patchJson("/api/proposals/{$proposal->id}/status", ['status' => 'shortlisted'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    });

    it('rejects a note longer than 500 characters', function () {
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        $this->actingAs($alex)->patchJson("/api/proposals/{$proposal->id}/status", [
            'status' => 'rejected', 'note' => str_repeat('x', 501),
        ])->assertStatus(422)->assertJsonValidationErrors('note');
    });

    it('writes no audit row when the status is unchanged', function () {
        $alex = User::factory()->admin()->create();
        $approved = Proposal::factory()->approved()->create();

        $this->actingAs($alex)->patchJson("/api/proposals/{$approved->id}/status", ['status' => 'approved'])
            ->assertOk()
            // Timing-free proof that changed_at comes from the audit row: a no-op
            // writes none, so it must be null where a recomputed now() would not be.
            ->assertJsonPath('changed_at', null);

        expect(ProposalStatusChange::count())->toBe(0);
    });
});
