<?php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('proposal detail', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('hides individual ratings from the owning speaker', function () {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->for($dana, 'author')->create();
        Review::factory()->create([
            'proposal_id' => $proposal->id, 'rating' => 4, 'comment' => 'Strong opening.',
        ]);

        $response = $this->actingAs($dana)->getJson("/api/proposals/{$proposal->id}");

        // Flat body, not {data: {...}}: matches every other
        // single-resource response and docs/API.md.
        $response->assertOk()
            ->assertJsonPath('reviews.0.comment', 'Strong opening.')
            ->assertJsonMissingPath('reviews.0.rating')
            ->assertJsonMissingPath('reviews.0.reviewer')
            ->assertJsonMissingPath('data');
    });

    it('shows full reviews to a reviewer', function () {
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();
        Review::factory()->create(['proposal_id' => $proposal->id, 'rating' => 4]);

        $response = $this->actingAs($maya)->getJson("/api/proposals/{$proposal->id}");

        $response->assertOk()
            ->assertJsonPath('reviews.0.rating', 4)
            ->assertJsonStructure(['reviews' => [['reviewer' => ['id', 'name', 'initials']]]]);
    });

    it('exposes my_review as an explicit null, not an omitted key, for a reviewer who has not reviewed', function () {
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();

        $response = $this->actingAs($maya)->getJson("/api/proposals/{$proposal->id}");

        // assertJsonPath fails on a missing key, so this also proves
        // the key is present, not just falsy. A typed client can then declare
        // my_review: Review | null instead of Review | undefined.
        $response->assertOk()->assertJsonPath('my_review', null);
    });

    it('surfaces max_rating from config', function () {
        config()->set('review.max_rating', 10);
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();

        $this->actingAs($maya)->getJson("/api/proposals/{$proposal->id}")
            ->assertOk()->assertJsonPath('max_rating', 10);
    });

    it('returns 404 when a speaker requests another speakers proposal', function () {
        $dana = User::factory()->speaker()->create();
        $theirs = Proposal::factory()->create();

        $this->actingAs($dana)->getJson("/api/proposals/{$theirs->id}")->assertNotFound();
    });
});
