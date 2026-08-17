<?php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('rating distribution', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('buckets every rating and zero-fills the rest', function () {
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 4]);
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 4]);
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 2]);

        $response = $this->actingAs($maya)->getJson("/api/proposals/{$proposal->id}");

        $response->assertOk()
            ->assertJsonPath('rating_distribution.1', 0)
            ->assertJsonPath('rating_distribution.2', 1)
            ->assertJsonPath('rating_distribution.3', 0)
            ->assertJsonPath('rating_distribution.4', 2)
            ->assertJsonPath('rating_distribution.5', 0);
    });

    it('sizes the buckets from config, not a hard-coded five', function () {
        config()->set('review.max_rating', 10);
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();

        $response = $this->actingAs($maya)->getJson("/api/proposals/{$proposal->id}");

        $response->assertOk()
            ->assertJsonPath('rating_distribution.10', 0)
            ->assertJsonCount(10, 'rating_distribution');
    });

    it('shows the distribution to the owning speaker', function () {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id]);
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 5]);

        $response = $this->actingAs($dana)->getJson("/api/proposals/{$proposal->id}");

        $response->assertOk()->assertJsonPath('rating_distribution.5', 1);
        expect($response->json('reviews.0'))->not->toHaveKey('rating');
    });

    it('drops ratings left behind by a lowered max_rating instead of clamping them', function () {
        // Ratings recorded while max_rating was 5. Lowering it afterwards is an
        // unsupported data migration: a genuine 5 must never be reported as the top
        // bucket of a smaller scale, so it is dropped rather than clamped — at the
        // cost of the histogram no longer summing to reviews_count.
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 3]);
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 4]);
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 5]);
        config()->set('review.max_rating', 2);

        $response = $this->actingAs($maya)->getJson("/api/proposals/{$proposal->id}");

        // Every bucket is zero — nothing was clamped into bucket 2 — while
        // reviews_count still reports the true total. The two disagree deliberately.
        $response->assertOk()
            ->assertJsonPath('rating_distribution.1', 0)
            ->assertJsonPath('rating_distribution.2', 0)
            ->assertJsonCount(2, 'rating_distribution')
            ->assertJsonPath('reviews_count', 3);

        $distribution = $response->json('rating_distribution');
        expect(array_sum($distribution))->not->toBe($response->json('reviews_count'));
    });
});
