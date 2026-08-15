<?php

// tests/Feature/Security/NotFoundEnumerationTest.php

// The `abort_unless($request->user()->can('view', $proposal), 404)` pattern
// exists so a real proposal id the caller may not see is indistinguishable
// from an id that does not exist at all — both must 404 with the same body.
// Before this fix the status code was closed but the body was not: a real,
// forbidden id reached the guard and got `{"message": ""}` (abort_unless's
// empty message), while a fake id failed route-model binding first and
// surfaced Eloquent's `ModelNotFoundException` message instead. That is a
// working enumeration oracle. These tests compare the two bodies byte-for-byte.

use App\Models\Proposal;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('API 404 enumeration guard', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    // All six `abort_unless($request->user()->can('view', $proposal), 404)` call
    // sites in the app, one dataset row each, so the coverage is countable at a
    // glance rather than six near-identical it() blocks: ProposalController's
    // update/destroy/destroyAttachment, ReviewController::store,
    // HistoryController, and StatusController::update. For every route, a real
    // proposal the outsider cannot view (guard fires after a successful
    // route-model bind) and a nonexistent id (route-model binding itself fails,
    // before the guard is ever reached) must be indistinguishable.
    it('gives an identical status and body for a forbidden id and a fake id', function (string $method, string $suffix, array $payload) {
        // Given
        $outsider = User::factory()->speaker()->create();
        $theirs = Proposal::factory()->create(['status' => 'pending']);

        // When
        $forbidden = $this->actingAs($outsider)->json($method, "/api/proposals/{$theirs->id}{$suffix}", $payload);
        $fake = $this->actingAs($outsider)->json($method, "/api/proposals/999999{$suffix}", $payload);

        // Then
        expect($forbidden->status())->toBe(404)
            ->and($fake->status())->toBe(404)
            ->and($forbidden->json())->toBe($fake->json());
    })->with([
        'ProposalController::update' => ['PATCH', '', ['title' => 'A perfectly valid title']],
        'ProposalController::destroy' => ['DELETE', '', []],
        'ProposalController::destroyAttachment' => ['DELETE', '/attachment', []],
        'ReviewController::store' => ['POST', '/reviews', ['rating' => 4]],
        'HistoryController' => ['GET', '/history', []],
        'StatusController::update' => ['PATCH', '/status', ['status' => 'approved']],
    ]);

    it('still returns a clean 401 for an unauthenticated request, not a 500', function () {
        // Given / When — no Sanctum token attached.
        $response = $this->getJson('/api/proposals/1/history');

        // Then — redirectGuestsTo(fn () => null) plus this fix must coexist:
        // the 401 path is untouched by the 404-normalisation hook.
        $response->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
    });

    it('leaves a non-API 404 alone', function () {
        // Given / When — a browser-style request (no JSON Accept header) to an
        // unmatched route. Task 7 will serve human-facing docs at /docs/api;
        // that path must keep Laravel's normal (non-JSON) 404 rendering.
        $response = $this->get('/this-route-does-not-exist');

        // Then
        $response->assertNotFound();
        expect($response->headers->get('Content-Type'))->not->toContain('application/json');
    });
});
