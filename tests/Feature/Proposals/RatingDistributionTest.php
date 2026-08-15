<?php

// tests/Feature/Proposals/RatingDistributionTest.php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('rating distribution', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('buckets every rating and zero-fills the rest', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 4]);
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 4]);
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 2]);

        // When
        $response = $this->actingAs($maya)->getJson("/api/proposals/{$proposal->id}");

        // Then
        $response->assertOk()
            ->assertJsonPath('rating_distribution.1', 0)
            ->assertJsonPath('rating_distribution.2', 1)
            ->assertJsonPath('rating_distribution.3', 0)
            ->assertJsonPath('rating_distribution.4', 2)
            ->assertJsonPath('rating_distribution.5', 0);
    });

    it('sizes the buckets from config, not a hard-coded five', function () {
        // Given — a non-default bound, so a hard-coded 1..5 fails here.
        config()->set('review.max_rating', 10);
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();

        // When
        $response = $this->actingAs($maya)->getJson("/api/proposals/{$proposal->id}");

        // Then
        $response->assertOk()
            ->assertJsonPath('rating_distribution.10', 0)
            ->assertJsonCount(10, 'rating_distribution');
    });

    it('shows the distribution to the owning speaker', function () {
        // Given — aggregates are visible to the author; only attribution is not.
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id]);
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 5]);

        // When
        $response = $this->actingAs($dana)->getJson("/api/proposals/{$proposal->id}");

        // Then
        $response->assertOk()->assertJsonPath('rating_distribution.5', 1);
        expect($response->json('reviews.0'))->not->toHaveKey('rating');
    });

    it('drops ratings left behind by a lowered max_rating instead of clamping them', function () {
        // Given — three ratings recorded while max_rating was 5. Lowering
        // max_rating afterwards is an unsupported data migration, not a
        // display concern: a genuine 5 must never be reported as the top
        // bucket of a smaller scale, so the API drops it rather than
        // clamping it in. That is a deliberate trade-off — the histogram
        // stops summing to reviews_count once this happens — pinned here so
        // it stays a known, chosen behaviour rather than an accident nobody
        // notices.
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 3]);
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 4]);
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 5]);
        config()->set('review.max_rating', 2);

        // When
        $response = $this->actingAs($maya)->getJson("/api/proposals/{$proposal->id}");

        // Then — every bucket in the now-smaller scale is zero: none of the
        // three out-of-range ratings were clamped into bucket 2. reviews_count
        // still reports the true total, so the two numbers intentionally
        // disagree rather than one of them lying.
        $response->assertOk()
            ->assertJsonPath('rating_distribution.1', 0)
            ->assertJsonPath('rating_distribution.2', 0)
            ->assertJsonCount(2, 'rating_distribution')
            ->assertJsonPath('reviews_count', 3);

        $distribution = $response->json('rating_distribution');
        expect(array_sum($distribution))->not->toBe($response->json('reviews_count'));
    });
});
