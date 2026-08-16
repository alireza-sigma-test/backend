<?php

// tests/Feature/Security/NotFoundEnumerationTest.php

// The `abort_unless($request->user()->can('view', $proposal), 404)` pattern
// (or, for the three Form-Request-guarded routes below, the equivalent check
// in the request's own authorize()) exists so a real proposal id the caller
// may not see is indistinguishable from an id that does not exist at all —
// both must 404 with the same body. Before this fix the status code was
// closed but the body was not: a real, forbidden id reached the guard and got
// `{"message": ""}` (abort_unless's empty message), while a fake id failed
// route-model binding first and surfaced Eloquent's `ModelNotFoundException`
// message instead. That is a working enumeration oracle. These tests compare
// the two bodies byte-for-byte.
//
// A second, later regression hid behind the first fix: three of these six
// routes (ProposalController::update, StatusController::update,
// ReviewController::store) run a Form Request, and Laravel validates that
// request — via FormRequest::validateResolved() — before the controller
// method body executes at all. A guard written as the controller's first
// line therefore never ran for a payload that failed validation, so a
// malformed request against those three routes still 422'd for a forbidden
// real id while 404'ing for a fake one — one request per id was enough to
// learn whether it existed. The dataset below has an invalid-payload row for
// each of those three routes, alongside the original valid-payload rows, so
// this file pins the property in the state that actually broke it.

use App\Models\Proposal;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('API 404 enumeration guard', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    // All six routes that hide a proposal's existence from a non-viewer, one
    // dataset row each for a well-formed payload, so the coverage is countable
    // at a glance rather than six near-identical it() blocks: ProposalController's
    // update/destroy/destroyAttachment, ReviewController::store,
    // HistoryController, and StatusController::update. Plus one invalid-payload
    // row for each of the three that run a Form Request — see the header
    // comment. For every route, a real proposal the outsider cannot view (the
    // guard fires after a successful route-model bind) and a nonexistent id
    // (route-model binding itself fails, before the guard is ever reached)
    // must be indistinguishable.
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
        // Invalid-payload rows — the state that actually broke the guard.
        'ProposalController::update (invalid payload)' => ['PATCH', '', ['title' => 'short']],
        'ReviewController::store (invalid payload)' => ['POST', '/reviews', ['rating' => 99]],
        'StatusController::update (invalid payload)' => ['PATCH', '/status', ['status' => 'bogus']],
    ]);

    // The other half of the property: closing the leak must not cost a
    // legitimate user their validation errors. A viewer who is allowed to see
    // the proposal has to clear the authorize() guard and then still hit the
    // validator, so a malformed payload from them 422s exactly as before.
    it('still 422s a genuinely invalid payload from a user who may view the proposal', function (string $method, string $suffix, array $payload, string $viewerRole, string $status) {
        // Given
        $viewer = match ($viewerRole) {
            'owner' => User::factory()->speaker()->create(),
            'admin' => User::factory()->admin()->create(),
            'reviewer' => User::factory()->reviewer()->create(),
        };
        $proposal = Proposal::factory()->create([
            'status' => $status,
            ...$viewerRole === 'owner' ? ['user_id' => $viewer->id] : [],
        ]);

        // When
        $response = $this->actingAs($viewer)->json($method, "/api/proposals/{$proposal->id}{$suffix}", $payload);

        // Then
        $response->assertStatus(422);
    })->with([
        'ProposalController::update' => ['PATCH', '', ['title' => 'short'], 'owner', 'pending'],
        'ReviewController::store' => ['POST', '/reviews', ['rating' => 99], 'reviewer', 'pending'],
        'StatusController::update' => ['PATCH', '/status', ['status' => 'bogus'], 'admin', 'pending'],
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
