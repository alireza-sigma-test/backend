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

    it('clears the comment when the client sends an explicit null', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $mine = Review::factory()->create([
            'user_id' => $maya->id, 'rating' => 2, 'comment' => 'Tighten the opening.',
        ]);

        // When
        $this->actingAs($maya)->patchJson("/api/reviews/{$mine->id}", ['comment' => null])->assertOk();

        // Then — matches how the create path stores a blank comment: null, not "".
        expect($mine->fresh()->comment)->toBeNull();
    });

    it('clears the comment when the client sends an empty string', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $mine = Review::factory()->create([
            'user_id' => $maya->id, 'rating' => 2, 'comment' => 'Tighten the opening.',
        ]);

        // When
        $this->actingAs($maya)->patchJson("/api/reviews/{$mine->id}", ['comment' => ''])->assertOk();

        // Then
        expect($mine->fresh()->comment)->toBeNull();
    });

    it('sets the comment when the client sends text', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $mine = Review::factory()->create(['user_id' => $maya->id, 'rating' => 2, 'comment' => null]);

        // When
        $this->actingAs($maya)
            ->patchJson("/api/reviews/{$mine->id}", ['comment' => '  Great structure.  '])->assertOk();

        // Then — trimmed, same as the create path.
        expect($mine->fresh()->comment)->toBe('Great structure.');
    });

    it('refuses a different reviewer', function () {
        // Given
        $mine = Review::factory()->create(['rating' => 2]);

        // When / Then
        $this->actingAs(User::factory()->reviewer()->create())
            ->patchJson("/api/reviews/{$mine->id}", ['rating' => 5])->assertForbidden();
        expect($mine->fresh()->rating)->toBe(2);
    });

    it('refuses an edit once the proposal has a decision', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->approved()->create();
        $mine = Review::factory()->create([
            'proposal_id' => $proposal->id, 'user_id' => $maya->id, 'rating' => 2,
        ]);

        // When / Then
        $this->actingAs($maya)->patchJson("/api/reviews/{$mine->id}", ['rating' => 5])->assertForbidden();
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

    it('nulls the average rather than zeroing it when the last review is deleted', function () {
        // Given — a zero here would render as a genuine score of zero.
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        $mine = Review::factory()->create([
            'proposal_id' => $proposal->id, 'user_id' => $maya->id, 'rating' => 3,
        ]);

        // When
        $this->actingAs($maya)->deleteJson("/api/reviews/{$mine->id}")->assertNoContent();

        // Then
        $this->actingAs($maya)->getJson("/api/proposals/{$proposal->id}")
            ->assertJsonPath('average_rating', null)
            ->assertJsonPath('reviews_count', 0);
    });

    it('refuses a delete from a different reviewer', function () {
        // Given
        $mine = Review::factory()->create();

        // When / Then
        $this->actingAs(User::factory()->reviewer()->create())
            ->deleteJson("/api/reviews/{$mine->id}")->assertForbidden();
        $this->assertDatabaseHas('reviews', ['id' => $mine->id]);
    });

    it('refuses a delete once the proposal has a decision', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->approved()->create();
        $mine = Review::factory()->create(['proposal_id' => $proposal->id, 'user_id' => $maya->id]);

        // When / Then
        $this->actingAs($maya)->deleteJson("/api/reviews/{$mine->id}")->assertForbidden();
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
