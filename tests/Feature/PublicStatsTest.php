<?php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('public stats', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('returns two counts without a token', function () {

        $response = $this->getJson('/api/public-stats');

        $response->assertOk()->assertJsonStructure(['proposals_this_year', 'reviewers']);
    });

    it('exposes nothing beyond those two keys', function () {
        $response = $this->getJson('/api/public-stats');

        // The exact key set. This endpoint is the app's only
        // unauthenticated read surface, so key creep here is a data leak.
        $response->assertOk();
        expect(array_keys($response->json()))->toEqualCanonicalizing(['proposals_this_year', 'reviewers']);
    });

    it('counts only proposals created this calendar year', function () {
        // One proposal from last year and one from this year, arranged
        // fully before the single call below — see the note on caching.
        $old = Proposal::factory()->create();
        $old->forceFill(['created_at' => now()->subYear()])->saveQuietly();
        Proposal::factory()->create();

        // One call only: the controller caches for 5 minutes and the array store is
        // sticky within a test, so a second call replays the first response.
        $response = $this->getJson('/api/public-stats');

        $response->assertOk()->assertJsonPath('proposals_this_year', 1);
    });

    it('excludes soft-deleted proposals', function () {
        // Two proposals created this year, one of them soft-deleted —
        // both arranged before the single call below, for the same caching
        // reason as the test above.
        Proposal::factory()->create();
        Proposal::factory()->create()->delete();

        $response = $this->getJson('/api/public-stats');

        // Proposal's SoftDeletes global scope excludes the deleted
        // row automatically; this is what makes the count come out at 1.
        $response->assertOk()->assertJsonPath('proposals_this_year', 1);
    });

    it('counts users holding the reviewer role', function () {
        // Three reviewers but only one review ever left by any of them —
        // if this endpoint counted distinct review authors instead of
        // role-holders, it would wrongly report 1.
        $reviewers = User::factory()->reviewer()->count(3)->create();
        Review::factory()->create(['user_id' => $reviewers->first()->id]);
        User::factory()->speaker()->create();
        User::factory()->admin()->create();

        $response = $this->getJson('/api/public-stats');

        $response->assertOk()->assertJsonPath('reviewers', 3);
    });

    it('is rate limited', function () {

        // 31 requests in the same window; throttle middleware runs
        // before the controller, so the response cache above cannot mask this.
        $responses = collect(range(1, 31))->map(fn () => $this->getJson('/api/public-stats'));

        expect($responses->take(30)->every(fn ($r) => $r->status() === 200))->toBeTrue();
        $responses->last()->assertStatus(429);
    });

    it('keys that rate limit by IP, not globally', function () {
        collect(range(1, 31))->each(fn () => $this->getJson('/api/public-stats'));
        $this->getJson('/api/public-stats')->assertStatus(429);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->getJson('/api/public-stats');

        // The test above cannot tell a per-IP limiter from a global one, since a
        // shared bucket also 429s on the 31st request. On the only anonymous route,
        // that difference is one scraper locking out every other visitor.
        $response->assertOk();
    });
});
