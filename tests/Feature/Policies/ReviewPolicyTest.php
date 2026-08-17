<?php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('review policy', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('lets the review author edit and delete their own review', function () {
        $maya = User::factory()->reviewer()->create();
        $review = Review::factory()->create([
            'proposal_id' => Proposal::factory()->create()->id,
            'user_id' => $maya->id,
        ]);

        expect($maya->can('update', $review))->toBeTrue()
            ->and($maya->can('delete', $review))->toBeTrue();
    });

    it('refuses another reviewer editing or deleting that review', function () {
        $jonas = User::factory()->reviewer()->create();
        $review = Review::factory()->create([
            'proposal_id' => Proposal::factory()->create()->id,
            'user_id' => User::factory()->reviewer()->create()->id,
        ]);

        expect($jonas->can('update', $review))->toBeFalse()
            ->and($jonas->can('delete', $review))->toBeFalse();
    });

    it('refuses an admin editing a reviewer\'s review', function () {
        $alex = User::factory()->admin()->create();
        $review = Review::factory()->create([
            'proposal_id' => Proposal::factory()->create()->id,
            'user_id' => User::factory()->reviewer()->create()->id,
        ]);

        expect($alex->can('update', $review))->toBeFalse()
            ->and($alex->can('delete', $review))->toBeFalse();
    });
});
