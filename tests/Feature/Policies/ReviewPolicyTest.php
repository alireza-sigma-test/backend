<?php
// tests/Feature/Policies/ReviewPolicyTest.php

use App\Models\{Proposal, Review, User};

describe('review policy', function () {

    beforeEach(fn () => $this->seed(Database\Seeders\RoleSeeder::class));

    it('lets the review author edit and delete their own review', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $review = Review::factory()->create([
            'proposal_id' => Proposal::factory()->create()->id,
            'user_id' => $maya->id,
        ]);

        // Then
        expect($maya->can('update', $review))->toBeTrue()
            ->and($maya->can('delete', $review))->toBeTrue();
    });

    it('refuses another reviewer editing or deleting that review', function () {
        // Given
        $jonas = User::factory()->reviewer()->create();
        $review = Review::factory()->create([
            'proposal_id' => Proposal::factory()->create()->id,
            'user_id' => User::factory()->reviewer()->create()->id,
        ]);

        // Then
        expect($jonas->can('update', $review))->toBeFalse()
            ->and($jonas->can('delete', $review))->toBeFalse();
    });

    it('refuses an admin editing a reviewer\'s review', function () {
        // Given — admins decide status; they do not touch ratings.
        $alex = User::factory()->admin()->create();
        $review = Review::factory()->create([
            'proposal_id' => Proposal::factory()->create()->id,
            'user_id' => User::factory()->reviewer()->create()->id,
        ]);

        // Then
        expect($alex->can('update', $review))->toBeFalse()
            ->and($alex->can('delete', $review))->toBeFalse();
    });
});
