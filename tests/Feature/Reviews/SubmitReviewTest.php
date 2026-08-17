<?php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('review submission', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('creates a review and returns the recomputed average', function () {
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 2]);

        $response = $this->actingAs($maya)->postJson("/api/proposals/{$proposal->id}/reviews", [
            'rating' => 4, 'comment' => 'Numbers-first and actionable.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('review.rating', 4)
            ->assertJsonPath('reviews_count', 2)
            ->assertJsonPath('average_rating', 3);
    });

    it('updates the existing review instead of creating a second one', function () {
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        $this->actingAs($maya)->postJson("/api/proposals/{$proposal->id}/reviews", ['rating' => 2]);

        $response = $this->actingAs($maya)->postJson("/api/proposals/{$proposal->id}/reviews", ['rating' => 5]);

        $response->assertCreated()
            ->assertJsonPath('reviews_count', 1)
            ->assertJsonPath('average_rating', 5);
        expect(Review::where('proposal_id', $proposal->id)->count())->toBe(1)
            ->and(Review::where('proposal_id', $proposal->id)->first()->rating)->toBe(5);
    });

    it('rejects a rating above max_rating', function () {
        // A non-default bound, so this only proves the rule reads
        // config rather than passing coincidentally against the default of 5.
        config()->set('review.max_rating', 3);
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();

        $this->actingAs($maya)->postJson("/api/proposals/{$proposal->id}/reviews", ['rating' => 4])
            ->assertStatus(422)->assertJsonValidationErrors('rating');

        $this->actingAs($maya)->postJson("/api/proposals/{$proposal->id}/reviews", ['rating' => 3])
            ->assertCreated();
    });

    it('refuses a review from a speaker', function () {
        // Must be viewable to the speaker (their own proposal) so this
        // exercises the review-policy denial, not the view-scoping 404 below.
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->for($dana, 'author')->create();

        $this->actingAs($dana)->postJson("/api/proposals/{$proposal->id}/reviews", ['rating' => 4])
            ->assertForbidden();
    });

    it('returns 404 for another speakers proposal instead of 403, so existence is not disclosed', function () {
        // A speaker can never view this proposal, so the route must
        // 404 before the review policy ever runs, matching ProposalController::show.
        $dana = User::factory()->speaker()->create();
        $theirs = Proposal::factory()->create();

        $this->actingAs($dana)->postJson("/api/proposals/{$theirs->id}/reviews", ['rating' => 4])
            ->assertNotFound();
    });

    it('refuses a review from an admin', function () {
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        $this->actingAs($alex)->postJson("/api/proposals/{$proposal->id}/reviews", ['rating' => 4])
            ->assertForbidden();
    });

    it('refuses a reviewer rating their own proposal', function () {
        $maya = User::factory()->reviewer()->create();
        $own = Proposal::factory()->for($maya, 'author')->create();

        $this->actingAs($maya)->postJson("/api/proposals/{$own->id}/reviews", ['rating' => 5])
            ->assertForbidden();
    });

    it('refuses a review once a decision exists', function () {
        $maya = User::factory()->reviewer()->create();
        $decided = Proposal::factory()->approved()->create();

        $this->actingAs($maya)->postJson("/api/proposals/{$decided->id}/reviews", ['rating' => 4])
            ->assertForbidden();
    });
});
