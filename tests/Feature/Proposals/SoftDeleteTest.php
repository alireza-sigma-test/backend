<?php

// tests/Feature/Proposals/SoftDeleteTest.php

use App\Models\Proposal;
use App\Models\ProposalStatusChange;
use App\Models\Review;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('soft-deleting a proposal', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('keeps reviews and audit rows when a proposal is deleted', function () {
        // Given a proposal with reviews and a status change
        $dana = User::factory()->speaker()->create();
        $admin = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        Review::factory()->count(2)->create(['proposal_id' => $proposal->id]);
        ProposalStatusChange::create([
            'proposal_id' => $proposal->id, 'from' => 'rejected', 'to' => 'pending',
            'note' => 'Reopened for reconsideration.', 'changed_by' => $admin->id,
        ]);

        // When the owner deletes it
        $this->actingAs($dana)->deleteJson("/api/proposals/{$proposal->id}")->assertNoContent();

        // Then the review rows and proposal_status_changes rows still exist.
        // This is the entire reason for soft-deleting: those tables declare
        // cascadeOnDelete, so a hard delete destroys every reviewer's work.
        expect(Review::where('proposal_id', $proposal->id)->count())->toBe(2)
            ->and(ProposalStatusChange::where('proposal_id', $proposal->id)->count())->toBe(1);
    });

    it('hides a deleted proposal from the list', function () {
        // Given
        $reviewer = User::factory()->reviewer()->create();
        Proposal::factory()->create()->delete();

        // When
        $response = $this->actingAs($reviewer)->getJson('/api/proposals');

        // Then — index excludes it
        $response->assertOk()->assertJsonCount(0, 'data');
    });

    it('404s on a deleted proposal', function () {
        // Given
        $reviewer = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        $proposal->delete();

        // When / Then — show returns 404, not 403
        $this->actingAs($reviewer)->getJson("/api/proposals/{$proposal->id}")->assertNotFound();
    });

    it('excludes deleted proposals from the counts block', function () {
        // Given
        $reviewer = User::factory()->reviewer()->create();
        Proposal::factory()->create(['status' => 'pending']);
        Proposal::factory()->create(['status' => 'pending'])->delete();

        // When
        $response = $this->actingAs($reviewer)->getJson('/api/proposals');

        // Then — the sidebar tallies
        $response->assertOk()->assertJsonPath('counts.all', 1)->assertJsonPath('counts.pending', 1);
    });

    it('excludes deleted proposals from GET /stats', function () {
        // Given
        $admin = User::factory()->admin()->create();
        Proposal::factory()->count(2)->create(['status' => 'pending']);
        Proposal::factory()->create(['status' => 'pending'])->delete();

        // When
        $response = $this->actingAs($admin)->getJson('/api/stats');

        // Then — total drops by one
        $response->assertOk()->assertJsonPath('total', 2);
    });

    it('excludes deleted proposals from a tag proposals_count', function () {
        // Given
        $reviewer = User::factory()->reviewer()->create();
        $tag = Tag::create(['name' => 'Databases']);
        $proposal = Proposal::factory()->create();
        $proposal->tags()->attach($tag);
        $deleted = Proposal::factory()->create();
        $deleted->tags()->attach($tag);
        $deleted->delete();

        // When — GET /tags
        $response = $this->actingAs($reviewer)->getJson('/api/tags');

        // Then
        $response->assertOk();
        $tagData = collect($response->json('data'))->firstWhere('name', 'Databases');
        expect($tagData['proposals_count'])->toBe(1);
    });
});
