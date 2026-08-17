<?php

// A real proposal id the caller may not see must be indistinguishable from one that
// does not exist — same status AND same body. Closing only the status left an oracle:
// a forbidden id got abort_unless's empty `{"message": ""}` while a fake id failed
// route-model binding and surfaced Eloquent's message. These compare bodies
// byte-for-byte.
//
// Three of the six routes run a Form Request, which Laravel validates before the
// controller body executes — so a guard written as the first line never ran for a
// malformed payload, and those routes 422'd for a forbidden real id while 404'ing for
// a fake one. The dataset carries an invalid-payload row for each of the three.

use App\Models\Proposal;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('API 404 enumeration guard', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    // All six routes that hide a proposal's existence, one row each so the coverage is
    // countable at a glance, plus an invalid-payload row for each of the three running
    // a Form Request. Per route, a real-but-hidden id (guard fires after a successful
    // bind) and a nonexistent one (binding itself fails) must be indistinguishable.
    it('gives an identical status and body for a forbidden id and a fake id', function (string $method, string $suffix, array $payload) {
        $outsider = User::factory()->speaker()->create();
        $theirs = Proposal::factory()->create(['status' => 'pending']);

        $forbidden = $this->actingAs($outsider)->json($method, "/api/proposals/{$theirs->id}{$suffix}", $payload);
        $fake = $this->actingAs($outsider)->json($method, "/api/proposals/999999{$suffix}", $payload);

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

    // The other half: closing the leak must not cost a legitimate viewer their
    // validation errors, so a malformed payload from them still 422s.
    it('still 422s a genuinely invalid payload from a user who may view the proposal', function (string $method, string $suffix, array $payload, string $viewerRole, string $status) {
        $viewer = match ($viewerRole) {
            'owner' => User::factory()->speaker()->create(),
            'admin' => User::factory()->admin()->create(),
            'reviewer' => User::factory()->reviewer()->create(),
        };
        $proposal = Proposal::factory()->create([
            'status' => $status,
            ...$viewerRole === 'owner' ? ['user_id' => $viewer->id] : [],
        ]);

        $response = $this->actingAs($viewer)->json($method, "/api/proposals/{$proposal->id}{$suffix}", $payload);

        $response->assertStatus(422);
    })->with([
        'ProposalController::update' => ['PATCH', '', ['title' => 'short'], 'owner', 'pending'],
        'ReviewController::store' => ['POST', '/reviews', ['rating' => 99], 'reviewer', 'pending'],
        'StatusController::update' => ['PATCH', '/status', ['status' => 'bogus'], 'admin', 'pending'],
    ]);

    it('still returns a clean 401 for an unauthenticated request, not a 500', function () {
        $response = $this->getJson('/api/proposals/1/history');

        // redirectGuestsTo(fn () => null) plus this fix must coexist:
        // the 401 path is untouched by the 404-normalisation hook.
        $response->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
    });

    it('leaves a non-API 404 alone', function () {
        // A browser-style request (no JSON Accept header) to an
        // unmatched route. Task 7 will serve human-facing docs at /docs/api;
        // that path must keep Laravel's normal (non-JSON) 404 rendering.
        $response = $this->get('/this-route-does-not-exist');

        $response->assertNotFound();
        expect($response->headers->get('Content-Type'))->not->toContain('application/json');
    });
});
