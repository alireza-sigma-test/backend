<?php

// tests/Feature/Auth/UnverifiedWriteGateTest.php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('the unverified write gate', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('lets an unverified user read', function () {
        // Given
        $user = User::factory()->reviewer()->unverified()->create();

        // When / Then — reading is explicitly allowed while unverified.
        $this->actingAs($user)->getJson('/api/proposals')->assertOk();
        $this->actingAs($user)->getJson('/api/tags')->assertOk();
    });

    it('refuses every write with a machine-readable marker', function () {
        // Given
        $speaker = User::factory()->speaker()->unverified()->create();
        $proposal = Proposal::factory()->create(['user_id' => $speaker->id, 'status' => 'pending']);

        // When
        $response = $this->actingAs($speaker)->postJson('/api/proposals', [
            'title' => 'A perfectly valid title', 'description' => str_repeat('a', 60),
        ]);

        // Then — the client must be able to tell this apart from a permission
        // refusal so it can prompt for the code rather than showing a dead end.
        $response->assertForbidden()->assertJsonPath('code', 'email_unverified');

        $this->actingAs($speaker)->patchJson("/api/proposals/{$proposal->id}", ['title' => 'Another valid title here'])
            ->assertForbidden();
        $this->actingAs($speaker)->deleteJson("/api/proposals/{$proposal->id}")->assertForbidden();
        $this->actingAs($speaker)->deleteJson("/api/proposals/{$proposal->id}/attachment")->assertForbidden();
    });

    it('refuses reviewing and deciding while unverified', function () {
        // Given
        $proposal = Proposal::factory()->create(['status' => 'pending']);
        $reviewer = User::factory()->reviewer()->unverified()->create();
        $admin = User::factory()->admin()->unverified()->create();
        $review = Review::factory()->create(['user_id' => $reviewer->id, 'proposal_id' => $proposal->id]);

        // When / Then
        $this->actingAs($reviewer)->postJson("/api/proposals/{$proposal->id}/reviews", ['rating' => 4])->assertForbidden();
        $this->actingAs($reviewer)->patchJson("/api/reviews/{$review->id}", ['rating' => 5])->assertForbidden();
        $this->actingAs($reviewer)->deleteJson("/api/reviews/{$review->id}")->assertForbidden();
        $this->actingAs($admin)->patchJson("/api/proposals/{$proposal->id}/status", ['status' => 'approved'])->assertForbidden();
    });

    it('tells the truth in the can object', function () {
        // Given — if `can` says true while the gate says no, the client renders
        // a form that cannot succeed. Each actor below is otherwise eligible
        // for exactly one of the three gated abilities the resource exposes,
        // so a policy conjunct dropped from any single one of them would flip
        // that key to true and go unnoticed if only `can.review` were checked.
        $owner = User::factory()->speaker()->unverified()->create();
        $proposal = Proposal::factory()->create(['user_id' => $owner->id, 'status' => 'pending']);
        $reviewer = User::factory()->reviewer()->unverified()->create();
        $admin = User::factory()->admin()->unverified()->create();

        // When / Then
        $this->actingAs($owner)->getJson("/api/proposals/{$proposal->id}")
            ->assertOk()->assertJsonPath('can.edit', false);
        $this->actingAs($reviewer)->getJson("/api/proposals/{$proposal->id}")
            ->assertOk()->assertJsonPath('can.review', false);
        $this->actingAs($admin)->getJson("/api/proposals/{$proposal->id}")
            ->assertOk()->assertJsonPath('can.change_status', false);
    });

    it('lets the same user write once verified', function () {
        // Given
        $speaker = User::factory()->speaker()->unverified()->create();

        // When
        $speaker->markEmailAsVerified();

        // Then
        $this->actingAs($speaker->fresh())->postJson('/api/proposals', [
            'title' => 'A perfectly valid title', 'description' => str_repeat('a', 60),
        ])->assertCreated();
    });

    // The open question from the task brief: middleware runs before the Form
    // Requests whose authorize() 404s a proposal the caller may not see, so
    // an unverified outsider now meets the `verified` gate first. Measured,
    // not assumed — left at Laravel's default middleware priority,
    // SubstituteBindings (route model binding) runs *before* any route
    // middleware that isn't explicitly prioritised, `verified` included. A
    // nonexistent id then fails binding and 404s before `verified` ever
    // runs, while a real-but-hidden id binds successfully and reaches
    // `verified`, which 403s — a status-code difference that discloses which
    // ids are real to a caller with no other privilege at all. Confirmed
    // experimentally before the fix (fake id => 404, hidden id => 403,
    // distinguishable) and pinned here after it: bootstrap/app.php's
    // prependToPriorityList moves `verified` ahead of SubstituteBindings, so
    // every id — real, hidden, or fake — gets the identical refusal below.
    it('refuses a hidden proposal and a nonexistent one identically, so an unverified outsider learns nothing about which ids exist', function () {
        // Given
        $owner = User::factory()->speaker()->create();
        $hidden = Proposal::factory()->create(['user_id' => $owner->id, 'status' => 'pending']);
        $outsider = User::factory()->speaker()->unverified()->create();
        $fakeId = $hidden->id + 999_000;

        // When
        $onHidden = $this->actingAs($outsider)->patchJson("/api/proposals/{$hidden->id}", ['title' => 'Another valid title here']);
        $onFake = $this->actingAs($outsider)->patchJson("/api/proposals/{$fakeId}", ['title' => 'Another valid title here']);

        // Then — same status, same body, for a real id the caller cannot see
        // and one that was never real at all.
        $onHidden->assertForbidden()->assertJsonPath('code', 'email_unverified');
        $onFake->assertForbidden()->assertExactJson($onHidden->json());

        // And the same holds for a route with no Form Request at all — the
        // plain `abort_unless(..., 404)` in ProposalController::destroy sits
        // even later in the pipeline than the Form Requests do.
        $destroyHidden = $this->actingAs($outsider)->deleteJson("/api/proposals/{$hidden->id}");
        $destroyFake = $this->actingAs($outsider)->deleteJson("/api/proposals/{$fakeId}");
        $destroyHidden->assertForbidden();
        $destroyFake->assertForbidden()->assertExactJson($destroyHidden->json());
    });

    // Same property, the two routes that bind a Review rather than a
    // Proposal. T2's enumeration test asserted this property across its six
    // routes and still missed one where it broke — a claim measured once and
    // pinned nowhere is one refactor from silently reverting, and these two
    // are exactly where a future change to binding/priority order would show
    // it first.
    it('refuses a hidden review and a nonexistent one identically, so an unverified outsider learns nothing about which review ids exist', function () {
        // Given
        $author = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create(['status' => 'pending']);
        $review = Review::factory()->create(['user_id' => $author->id, 'proposal_id' => $proposal->id]);
        $outsider = User::factory()->reviewer()->unverified()->create();
        $fakeId = $review->id + 999_000;

        // When
        $onHidden = $this->actingAs($outsider)->patchJson("/api/reviews/{$review->id}", ['rating' => 5]);
        $onFake = $this->actingAs($outsider)->patchJson("/api/reviews/{$fakeId}", ['rating' => 5]);

        // Then — same status, same body, for someone else's review and a
        // review id that never existed.
        $onHidden->assertForbidden()->assertJsonPath('code', 'email_unverified');
        $onFake->assertForbidden()->assertExactJson($onHidden->json());

        $destroyHidden = $this->actingAs($outsider)->deleteJson("/api/reviews/{$review->id}");
        $destroyFake = $this->actingAs($outsider)->deleteJson("/api/reviews/{$fakeId}");
        $destroyHidden->assertForbidden();
        $destroyFake->assertForbidden()->assertExactJson($destroyHidden->json());
    });
});
