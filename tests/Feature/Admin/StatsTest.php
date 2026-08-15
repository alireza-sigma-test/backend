<?php

// tests/Feature/Admin/StatsTest.php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('admin stats', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('counts every proposal by status', function () {
        // Given
        $alex = User::factory()->admin()->create();
        Proposal::factory()->count(3)->create(['status' => 'pending']);
        Proposal::factory()->count(2)->create(['status' => 'approved']);
        Proposal::factory()->create(['status' => 'rejected']);

        // When
        $response = $this->actingAs($alex)->getJson('/api/stats');

        // Then
        $response->assertOk()
            ->assertJsonPath('total', 6)
            ->assertJsonPath('pending', 3)
            ->assertJsonPath('approved', 2)
            ->assertJsonPath('rejected', 1);
    });

    it('counts only pending proposals that clear the review threshold', function () {
        // Given — threshold set to 3, away from the default of 2, so a
        // hard-coded or defaulted implementation fails this.
        config()->set('review.min_reviews_to_decide', 3);
        $alex = User::factory()->admin()->create();

        $ready = Proposal::factory()->create(['status' => 'pending']);
        Review::factory()->count(3)->create(['proposal_id' => $ready->id]);

        $notEnough = Proposal::factory()->create(['status' => 'pending']);
        Review::factory()->count(2)->create(['proposal_id' => $notEnough->id]);

        // Decided proposals never count, however many reviews they carry.
        $decided = Proposal::factory()->create(['status' => 'approved']);
        Review::factory()->count(5)->create(['proposal_id' => $decided->id]);

        // When / Then
        $this->actingAs($alex)->getJson('/api/stats')
            ->assertOk()->assertJsonPath('ready_to_decide', 1);
    });

    it('refuses a reviewer and a speaker', function () {
        // When / Then
        $this->actingAs(User::factory()->reviewer()->create())
            ->getJson('/api/stats')->assertForbidden();
        $this->actingAs(User::factory()->speaker()->create())
            ->getJson('/api/stats')->assertForbidden();
    });
});
