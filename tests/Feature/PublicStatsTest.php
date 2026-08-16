<?php

// tests/Feature/PublicStatsTest.php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('public stats', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('returns two counts without a token', function () {
        // Given no authentication at all

        // When
        $response = $this->getJson('/api/public-stats');

        // Then
        $response->assertOk()->assertJsonStructure(['proposals_this_year', 'reviewers']);
    });

    it('exposes nothing beyond those two keys', function () {
        // Given / When
        $response = $this->getJson('/api/public-stats');

        // Then — assert the exact key set. This endpoint is the app's only
        // unauthenticated read surface, so key creep here is a data leak.
        $response->assertOk();
        expect(array_keys($response->json()))->toEqualCanonicalizing(['proposals_this_year', 'reviewers']);
    });

    it('counts only proposals created this calendar year', function () {
        // Given one proposal from last year and one from this year, arranged
        // fully before the single call below — see the note on caching.
        $old = Proposal::factory()->create();
        $old->forceFill(['created_at' => now()->subYear()])->saveQuietly();
        Proposal::factory()->create();

        // When — one call only. The controller caches its result for 5
        // minutes and phpunit.xml's CACHE_STORE=array is fresh per test but
        // sticky within one, so a second call here would just replay the
        // first response instead of re-querying.
        $response = $this->getJson('/api/public-stats');

        // Then
        $response->assertOk()->assertJsonPath('proposals_this_year', 1);
    });

    it('excludes soft-deleted proposals', function () {
        // Given two proposals created this year, one of them soft-deleted —
        // both arranged before the single call below, for the same caching
        // reason as the test above.
        Proposal::factory()->create();
        Proposal::factory()->create()->delete();

        // When
        $response = $this->getJson('/api/public-stats');

        // Then — Proposal's SoftDeletes global scope excludes the deleted
        // row automatically; this is what makes the count come out at 1.
        $response->assertOk()->assertJsonPath('proposals_this_year', 1);
    });

    it('counts users holding the reviewer role', function () {
        // Given three reviewers but only one review ever left by any of them —
        // if this endpoint counted distinct review authors instead of
        // role-holders, it would wrongly report 1.
        $reviewers = User::factory()->reviewer()->count(3)->create();
        Review::factory()->create(['user_id' => $reviewers->first()->id]);
        User::factory()->speaker()->create();
        User::factory()->admin()->create();

        // When
        $response = $this->getJson('/api/public-stats');

        // Then
        $response->assertOk()->assertJsonPath('reviewers', 3);
    });

    it('is rate limited', function () {
        // Given the named limiter allows 30 requests per minute per IP

        // When — 31 requests in the same window; throttle middleware runs
        // before the controller, so the response cache above cannot mask this.
        $responses = collect(range(1, 31))->map(fn () => $this->getJson('/api/public-stats'));

        // Then — the first 30 go through, the 31st is throttled.
        expect($responses->take(30)->every(fn ($r) => $r->status() === 200))->toBeTrue();
        $responses->last()->assertStatus(429);
    });
});
