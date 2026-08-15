<?php

// tests/Feature/Admin/HistoryTest.php

use App\Models\Proposal;
use App\Models\ProposalStatusChange;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('proposal status history', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('returns the audit trail newest first for an admin', function () {
        // Given
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();
        ProposalStatusChange::create([
            'proposal_id' => $proposal->id, 'from' => 'pending', 'to' => 'approved',
            'note' => 'Strong fit.', 'changed_by' => $alex->id,
        ]);

        // When
        $response = $this->actingAs($alex)->getJson("/api/proposals/{$proposal->id}/history");

        // Then
        $response->assertOk()
            ->assertJsonPath('data.0.from', 'pending')
            ->assertJsonPath('data.0.to', 'approved')
            ->assertJsonPath('data.0.note', 'Strong fit.')
            ->assertJsonPath('data.0.changed_by.name', $alex->name);
        expect($response->json('data.0.changed_at'))->not->toBeNull();
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
});
