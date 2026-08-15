<?php

// tests/Feature/Proposals/DeleteProposalTest.php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('deleting a proposal', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('lets the owning speaker delete it while pending', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

        // When / Then
        $this->actingAs($dana)->deleteJson("/api/proposals/{$proposal->id}")->assertNoContent();
        $this->assertDatabaseMissing('proposals', ['id' => $proposal->id]);
    });

    it('refuses the owning speaker once a decision exists', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'approved']);

        // When / Then
        $this->actingAs($dana)->deleteJson("/api/proposals/{$proposal->id}")->assertForbidden();
        $this->assertDatabaseHas('proposals', ['id' => $proposal->id]);
    });

    it('lets an admin delete a decided proposal', function () {
        // Given
        $proposal = Proposal::factory()->create(['status' => 'approved']);

        // When / Then
        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson("/api/proposals/{$proposal->id}")->assertNoContent();
        $this->assertDatabaseMissing('proposals', ['id' => $proposal->id]);
    });

    it('takes the reviews with it', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        Review::factory()->count(2)->create(['proposal_id' => $proposal->id]);

        // When
        $this->actingAs($dana)->deleteJson("/api/proposals/{$proposal->id}")->assertNoContent();

        // Then — orphaned review rows would break every average that follows.
        expect(Review::where('proposal_id', $proposal->id)->count())->toBe(0);
    });

    it('removes the attachment without touching the proposal', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        $proposal->addMedia(fakePdf('slides.pdf'))
            ->toMediaCollection(Proposal::ATTACHMENT_COLLECTION);
        expect($proposal->fresh()->attachment())->not->toBeNull();

        // When
        $this->actingAs($dana)->deleteJson("/api/proposals/{$proposal->id}/attachment")->assertNoContent();

        // Then
        expect($proposal->fresh()->attachment())->toBeNull();
        $this->assertDatabaseHas('proposals', ['id' => $proposal->id]);
    });

    it('404s rather than 403s for a non-owner', function () {
        // Given
        $proposal = Proposal::factory()->create(['status' => 'pending']);

        // When / Then
        $this->actingAs(User::factory()->speaker()->create())
            ->deleteJson("/api/proposals/{$proposal->id}")->assertNotFound();
    });
});
