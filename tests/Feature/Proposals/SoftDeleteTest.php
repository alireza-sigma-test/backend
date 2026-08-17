<?php

use App\Models\Proposal;
use App\Models\ProposalStatusChange;
use App\Models\Review;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

describe('soft-deleting a proposal', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('keeps reviews and audit rows when a proposal is deleted', function () {
        $dana = User::factory()->speaker()->create();
        $admin = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        Review::factory()->count(2)->create(['proposal_id' => $proposal->id]);
        ProposalStatusChange::create([
            'proposal_id' => $proposal->id, 'from' => 'rejected', 'to' => 'pending',
            'note' => 'Reopened for reconsideration.', 'changed_by' => $admin->id,
        ]);

        $this->actingAs($dana)->deleteJson("/api/proposals/{$proposal->id}")->assertNoContent();

        // The review rows and proposal_status_changes rows still exist.
        // This is the entire reason for soft-deleting: those tables declare
        // cascadeOnDelete, so a hard delete destroys every reviewer's work.
        expect(Review::where('proposal_id', $proposal->id)->count())->toBe(2)
            ->and(ProposalStatusChange::where('proposal_id', $proposal->id)->count())->toBe(1);
    });

    it('hides a deleted proposal from the list', function () {
        $reviewer = User::factory()->reviewer()->create();
        Proposal::factory()->create()->delete();

        $response = $this->actingAs($reviewer)->getJson('/api/proposals');

        $response->assertOk()->assertJsonCount(0, 'data');
    });

    it('404s on a deleted proposal', function () {
        $reviewer = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        $proposal->delete();

        $this->actingAs($reviewer)->getJson("/api/proposals/{$proposal->id}")->assertNotFound();
    });

    it('excludes deleted proposals from the counts block', function () {
        $reviewer = User::factory()->reviewer()->create();
        Proposal::factory()->create(['status' => 'pending']);
        Proposal::factory()->create(['status' => 'pending'])->delete();

        $response = $this->actingAs($reviewer)->getJson('/api/proposals');

        $response->assertOk()->assertJsonPath('counts.all', 1)->assertJsonPath('counts.pending', 1);
    });

    it('excludes deleted proposals from GET /stats', function () {
        $admin = User::factory()->admin()->create();
        Proposal::factory()->count(2)->create(['status' => 'pending']);
        Proposal::factory()->create(['status' => 'pending'])->delete();

        $response = $this->actingAs($admin)->getJson('/api/stats');

        $response->assertOk()->assertJsonPath('total', 2);
    });

    it('does not fatal when loading a review whose proposal was deleted', function () {
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        $review = Review::factory()->create(['proposal_id' => $proposal->id, 'user_id' => $maya->id]);

        $proposal->delete();

        // A clean 403, not a 500 from dereferencing the now-null $review->proposal.
        $this->actingAs($maya)->patchJson("/api/reviews/{$review->id}", ['rating' => 5])
            ->assertForbidden();

        $this->actingAs($maya)->deleteJson("/api/reviews/{$review->id}")
            ->assertForbidden();
    });

    it('purges the attachment when a proposal is soft-deleted', function () {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        $proposal->addMedia(fakePdf('slides.pdf'))
            ->toMediaCollection(Proposal::ATTACHMENT_COLLECTION);
        expect(Media::where('model_id', $proposal->id)->count())->toBe(1);

        $this->actingAs($dana)->deleteJson("/api/proposals/{$proposal->id}")->assertNoContent();

        // The media row is gone though the proposal row survives. Only
        // DeleteProposal's explicit remove() does this — Media Library's `deleting`
        // listener returns early for a soft-deleting model.
        $this->assertSoftDeleted('proposals', ['id' => $proposal->id]);
        expect(Media::where('model_id', $proposal->id)->count())->toBe(0);
    });

    it('excludes deleted proposals from a tag proposals_count', function () {
        $reviewer = User::factory()->reviewer()->create();
        $tag = Tag::create(['name' => 'Databases']);
        $proposal = Proposal::factory()->create();
        $proposal->tags()->attach($tag);
        $deleted = Proposal::factory()->create();
        $deleted->tags()->attach($tag);
        $deleted->delete();

        $response = $this->actingAs($reviewer)->getJson('/api/tags');

        $response->assertOk();
        $tagData = collect($response->json('data'))->firstWhere('name', 'Databases');
        expect($tagData['proposals_count'])->toBe(1);
    });
});
