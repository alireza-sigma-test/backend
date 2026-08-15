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

    it('gives identical bodies for a forbidden id and a fake id where the abort_unless guard fires (status change)', function () {
        // Given — a speaker who owns neither proposal, so both requests 404:
        // the real one via ProposalController-style abort_unless, the fake one
        // via a route-model-binding failure that never reaches the guard at all.
        $outsider = User::factory()->speaker()->create();
        $theirs = Proposal::factory()->create(['status' => 'pending']);

        // When
        $forbidden = $this->actingAs($outsider)
            ->patchJson("/api/proposals/{$theirs->id}/status", ['status' => 'approved']);
        $fake = $this->actingAs($outsider)
            ->patchJson('/api/proposals/999999/status', ['status' => 'approved']);

        // Then
        $forbidden->assertNotFound();
        $fake->assertNotFound();
        expect($forbidden->headers->get('Content-Type'))->toBe($fake->headers->get('Content-Type'));
        expect($forbidden->json())->toBe($fake->json());
    });

    it('gives identical bodies for a forbidden id and a fake id where route-model binding is the only gate (history)', function () {
        // Given — HistoryController runs the same abort_unless(can('view')) guard
        // before its own authorize('viewHistory'), so this is a second call site,
        // not a retest of the same controller method.
        $outsider = User::factory()->speaker()->create();
        $theirs = Proposal::factory()->create(['status' => 'approved']);

        // When
        $forbidden = $this->actingAs($outsider)->getJson("/api/proposals/{$theirs->id}/history");
        $fake = $this->actingAs($outsider)->getJson('/api/proposals/999999/history');

        // Then
        $forbidden->assertNotFound();
        $fake->assertNotFound();
        expect($forbidden->headers->get('Content-Type'))->toBe($fake->headers->get('Content-Type'));
        expect($forbidden->json())->toBe($fake->json());
    });

    it('gives identical bodies for a forbidden id and a fake id on the new delete endpoint', function () {
        // Given — Task 5's own guard, exercised the same way.
        $outsider = User::factory()->speaker()->create();
        $theirs = Proposal::factory()->create(['status' => 'pending']);

        // When
        $forbidden = $this->actingAs($outsider)->deleteJson("/api/proposals/{$theirs->id}");
        $fake = $this->actingAs($outsider)->deleteJson('/api/proposals/999999');

        // Then
        $forbidden->assertNotFound();
        $fake->assertNotFound();
        expect($forbidden->headers->get('Content-Type'))->toBe($fake->headers->get('Content-Type'));
        expect($forbidden->json())->toBe($fake->json());
    });

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
