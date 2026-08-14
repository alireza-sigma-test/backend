<?php
// tests/Feature/Reviews/SubmitReviewTest.php

use App\Models\{Proposal, Review, User};

describe('review submission', function () {

    beforeEach(fn () => $this->seed(Database\Seeders\RoleSeeder::class));

    it('creates a review and returns the recomputed average', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 2]);

        // When
        $response = $this->actingAs($maya)->postJson("/api/proposals/{$proposal->id}/reviews", [
            'rating' => 4, 'comment' => 'Numbers-first and actionable.',
        ]);

        // Then
        $response->assertCreated()
            ->assertJsonPath('review.rating', 4)
            ->assertJsonPath('reviews_count', 2)
            ->assertJsonPath('average_rating', 3.0);
    });

    it('updates the existing review instead of creating a second one', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        $this->actingAs($maya)->postJson("/api/proposals/{$proposal->id}/reviews", ['rating' => 2]);

        // When
        $response = $this->actingAs($maya)->postJson("/api/proposals/{$proposal->id}/reviews", ['rating' => 5]);

        // Then
        $response->assertCreated()->assertJsonPath('reviews_count', 1);
        expect(Review::where('proposal_id', $proposal->id)->count())->toBe(1)
            ->and(Review::where('proposal_id', $proposal->id)->first()->rating)->toBe(5);
    });

    it('rejects a rating above max_rating', function () {
        // Given
        config()->set('review.max_rating', 5);
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();

        // When / Then
        $this->actingAs($maya)->postJson("/api/proposals/{$proposal->id}/reviews", ['rating' => 6])
            ->assertStatus(422)->assertJsonValidationErrors('rating');
    });

    it('refuses a review from a speaker', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create();

        // When / Then
        $this->actingAs($dana)->postJson("/api/proposals/{$proposal->id}/reviews", ['rating' => 4])
            ->assertForbidden();
    });

    it('refuses a review from an admin', function () {
        // Given
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        // When / Then
        $this->actingAs($alex)->postJson("/api/proposals/{$proposal->id}/reviews", ['rating' => 4])
            ->assertForbidden();
    });

    it('refuses a reviewer rating their own proposal', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $own = Proposal::factory()->for($maya, 'author')->create();

        // When / Then
        $this->actingAs($maya)->postJson("/api/proposals/{$own->id}/reviews", ['rating' => 5])
            ->assertForbidden();
    });

    it('refuses a review once a decision exists', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $decided = Proposal::factory()->approved()->create();

        // When / Then
        $this->actingAs($maya)->postJson("/api/proposals/{$decided->id}/reviews", ['rating' => 4])
            ->assertForbidden();
    });
});
