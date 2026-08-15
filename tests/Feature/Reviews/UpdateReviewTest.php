<?php

// tests/Feature/Reviews/UpdateReviewTest.php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('editing and deleting a review', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('lets the author change the rating and recomputes the average', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        $mine = Review::factory()->create([
            'proposal_id' => $proposal->id, 'user_id' => $maya->id, 'rating' => 2,
        ]);
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 4]);

        // When
        $response = $this->actingAs($maya)->patchJson("/api/reviews/{$mine->id}", ['rating' => 5]);

        // Then — (5 + 4) / 2
        $response->assertOk()
            ->assertJsonPath('review.rating', 5)
            ->assertJsonPath('average_rating', 4.5)
            ->assertJsonPath('reviews_count', 2);
    });

    it('leaves the comment alone when only the rating is sent', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $mine = Review::factory()->create([
            'user_id' => $maya->id, 'rating' => 2, 'comment' => 'Tighten the opening.',
        ]);

        // When
        $this->actingAs($maya)->patchJson("/api/reviews/{$mine->id}", ['rating' => 3])->assertOk();

        // Then
        expect($mine->fresh()->comment)->toBe('Tighten the opening.');
    });

    it('refuses a different reviewer', function () {
        // Given
        $mine = Review::factory()->create(['rating' => 2]);

        // When / Then
        $this->actingAs(User::factory()->reviewer()->create())
            ->patchJson("/api/reviews/{$mine->id}", ['rating' => 5])->assertForbidden();
        expect($mine->fresh()->rating)->toBe(2);
    });

    it('deletes the author own review and recomputes the average', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        $mine = Review::factory()->create([
            'proposal_id' => $proposal->id, 'user_id' => $maya->id, 'rating' => 1,
        ]);
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 5]);

        // When
        $this->actingAs($maya)->deleteJson("/api/reviews/{$mine->id}")->assertNoContent();

        // Then
        $this->assertDatabaseMissing('reviews', ['id' => $mine->id]);
        $this->actingAs($maya)->getJson("/api/proposals/{$proposal->id}")
            ->assertJsonPath('average_rating', 5)
            ->assertJsonPath('reviews_count', 1);
    });

    it('refuses a delete from a different reviewer', function () {
        // Given
        $mine = Review::factory()->create();

        // When / Then
        $this->actingAs(User::factory()->reviewer()->create())
            ->deleteJson("/api/reviews/{$mine->id}")->assertForbidden();
        $this->assertDatabaseHas('reviews', ['id' => $mine->id]);
    });

    it('still bounds the rating by max_rating', function () {
        // Given — non-default so the rule is proven to read config.
        config()->set('review.max_rating', 3);
        $maya = User::factory()->reviewer()->create();
        $mine = Review::factory()->create(['user_id' => $maya->id, 'rating' => 2]);

        // When / Then
        $this->actingAs($maya)->patchJson("/api/reviews/{$mine->id}", ['rating' => 4])
            ->assertStatus(422)->assertJsonValidationErrors('rating');
    });
});
