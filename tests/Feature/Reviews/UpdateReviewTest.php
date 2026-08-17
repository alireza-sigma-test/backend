<?php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('editing and deleting a review', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('lets the author change the rating and recomputes the average', function () {
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        $mine = Review::factory()->create([
            'proposal_id' => $proposal->id, 'user_id' => $maya->id, 'rating' => 2,
        ]);
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 4]);

        $response = $this->actingAs($maya)->patchJson("/api/reviews/{$mine->id}", ['rating' => 5]);

        $response->assertOk()
            ->assertJsonPath('review.rating', 5)
            ->assertJsonPath('average_rating', 4.5)
            ->assertJsonPath('reviews_count', 2);
    });

    it('leaves the comment alone when only the rating is sent', function () {
        $maya = User::factory()->reviewer()->create();
        $mine = Review::factory()->create([
            'user_id' => $maya->id, 'rating' => 2, 'comment' => 'Tighten the opening.',
        ]);

        $this->actingAs($maya)->patchJson("/api/reviews/{$mine->id}", ['rating' => 3])->assertOk();

        expect($mine->fresh()->comment)->toBe('Tighten the opening.');
    });

    it('clears the comment when the client sends an explicit null', function () {
        $maya = User::factory()->reviewer()->create();
        $mine = Review::factory()->create([
            'user_id' => $maya->id, 'rating' => 2, 'comment' => 'Tighten the opening.',
        ]);

        $this->actingAs($maya)->patchJson("/api/reviews/{$mine->id}", ['comment' => null])->assertOk();

        expect($mine->fresh()->comment)->toBeNull();
    });

    it('clears the comment when the client sends an empty string', function () {
        $maya = User::factory()->reviewer()->create();
        $mine = Review::factory()->create([
            'user_id' => $maya->id, 'rating' => 2, 'comment' => 'Tighten the opening.',
        ]);

        $this->actingAs($maya)->patchJson("/api/reviews/{$mine->id}", ['comment' => ''])->assertOk();

        expect($mine->fresh()->comment)->toBeNull();
    });

    it('sets the comment when the client sends text', function () {
        $maya = User::factory()->reviewer()->create();
        $mine = Review::factory()->create(['user_id' => $maya->id, 'rating' => 2, 'comment' => null]);

        $this->actingAs($maya)
            ->patchJson("/api/reviews/{$mine->id}", ['comment' => '  Great structure.  '])->assertOk();

        expect($mine->fresh()->comment)->toBe('Great structure.');
    });

    it('refuses a different reviewer', function () {
        $mine = Review::factory()->create(['rating' => 2]);

        $this->actingAs(User::factory()->reviewer()->create())
            ->patchJson("/api/reviews/{$mine->id}", ['rating' => 5])->assertForbidden();
        expect($mine->fresh()->rating)->toBe(2);
    });

    it('refuses an edit once the proposal has a decision', function () {
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->approved()->create();
        $mine = Review::factory()->create([
            'proposal_id' => $proposal->id, 'user_id' => $maya->id, 'rating' => 2,
        ]);

        $this->actingAs($maya)->patchJson("/api/reviews/{$mine->id}", ['rating' => 5])->assertForbidden();
        expect($mine->fresh()->rating)->toBe(2);
    });

    it('deletes the author own review and recomputes the average', function () {
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        $mine = Review::factory()->create([
            'proposal_id' => $proposal->id, 'user_id' => $maya->id, 'rating' => 1,
        ]);
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 5]);

        $this->actingAs($maya)->deleteJson("/api/reviews/{$mine->id}")->assertNoContent();

        $this->assertDatabaseMissing('reviews', ['id' => $mine->id]);
        $this->actingAs($maya)->getJson("/api/proposals/{$proposal->id}")
            ->assertJsonPath('average_rating', 5)
            ->assertJsonPath('reviews_count', 1);
    });

    it('nulls the average rather than zeroing it when the last review is deleted', function () {
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        $mine = Review::factory()->create([
            'proposal_id' => $proposal->id, 'user_id' => $maya->id, 'rating' => 3,
        ]);

        $this->actingAs($maya)->deleteJson("/api/reviews/{$mine->id}")->assertNoContent();

        $this->actingAs($maya)->getJson("/api/proposals/{$proposal->id}")
            ->assertJsonPath('average_rating', null)
            ->assertJsonPath('reviews_count', 0);
    });

    it('refuses a delete from a different reviewer', function () {
        $mine = Review::factory()->create();

        $this->actingAs(User::factory()->reviewer()->create())
            ->deleteJson("/api/reviews/{$mine->id}")->assertForbidden();
        $this->assertDatabaseHas('reviews', ['id' => $mine->id]);
    });

    it('refuses a delete once the proposal has a decision', function () {
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->approved()->create();
        $mine = Review::factory()->create(['proposal_id' => $proposal->id, 'user_id' => $maya->id]);

        $this->actingAs($maya)->deleteJson("/api/reviews/{$mine->id}")->assertForbidden();
        $this->assertDatabaseHas('reviews', ['id' => $mine->id]);
    });

    it('still bounds the rating by max_rating', function () {
        config()->set('review.max_rating', 3);
        $maya = User::factory()->reviewer()->create();
        $mine = Review::factory()->create(['user_id' => $maya->id, 'rating' => 2]);

        $this->actingAs($maya)->patchJson("/api/reviews/{$mine->id}", ['rating' => 4])
            ->assertStatus(422)->assertJsonValidationErrors('rating');
    });
});
