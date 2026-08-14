<?php
// tests/Feature/Admin/ChangeStatusTest.php

use App\Enums\ProposalStatus;
use App\Models\{Proposal, ProposalStatusChange, User};

describe('admin status changes', function () {

    beforeEach(fn () => $this->seed(Database\Seeders\RoleSeeder::class));

    it('approves a proposal and writes an audit row', function () {
        // Given
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        // When
        $response = $this->actingAs($alex)->patchJson("/api/proposals/{$proposal->id}/status", [
            'status' => 'approved',
        ]);

        // Then
        $response->assertOk()
            ->assertJsonPath('proposal.status', 'approved')
            ->assertJsonPath('changed_by.name', $alex->name);

        $audit = ProposalStatusChange::where('proposal_id', $proposal->id)->sole();

        expect($audit->from)->toBe(ProposalStatus::Pending)
            ->and($audit->to)->toBe(ProposalStatus::Approved)
            ->and($audit->changed_by)->toBe($alex->id);
    });

    it('stores the rejection note', function () {
        // Given
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        // When
        $this->actingAs($alex)->patchJson("/api/proposals/{$proposal->id}/status", [
            'status' => 'rejected', 'note' => 'Overlaps with an accepted talk.',
        ])->assertOk();

        // Then
        expect(ProposalStatusChange::sole()->note)->toBe('Overlaps with an accepted talk.');
    });

    it('refuses a status change from a reviewer', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();

        // When / Then
        $this->actingAs($maya)->patchJson("/api/proposals/{$proposal->id}/status", ['status' => 'approved'])
            ->assertForbidden();

        expect(ProposalStatusChange::count())->toBe(0);
    });

    it('refuses a status change from the owning speaker', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->for($dana, 'author')->create();

        // When / Then
        $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}/status", ['status' => 'approved'])
            ->assertForbidden();
    });

    it('rejects an unknown status value', function () {
        // Given
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        // When / Then
        $this->actingAs($alex)->patchJson("/api/proposals/{$proposal->id}/status", ['status' => 'shortlisted'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    });

    it('rejects a note longer than 500 characters', function () {
        // Given
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        // When / Then
        $this->actingAs($alex)->patchJson("/api/proposals/{$proposal->id}/status", [
            'status' => 'rejected', 'note' => str_repeat('x', 501),
        ])->assertStatus(422)->assertJsonValidationErrors('note');
    });

    it('writes no audit row when the status is unchanged', function () {
        // Given
        $alex = User::factory()->admin()->create();
        $approved = Proposal::factory()->approved()->create();

        // When
        $this->actingAs($alex)->patchJson("/api/proposals/{$approved->id}/status", ['status' => 'approved'])
            ->assertOk();

        // Then
        expect(ProposalStatusChange::count())->toBe(0);
    });
});
