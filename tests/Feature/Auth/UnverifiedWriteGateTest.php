<?php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('the unverified write gate', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('lets an unverified user read', function () {
        $user = User::factory()->reviewer()->unverified()->create();

        $this->actingAs($user)->getJson('/api/proposals')->assertOk();
        $this->actingAs($user)->getJson('/api/tags')->assertOk();
    });

    it('refuses every write with a machine-readable marker', function () {
        $speaker = User::factory()->speaker()->unverified()->create();
        $proposal = Proposal::factory()->create(['user_id' => $speaker->id, 'status' => 'pending']);

        $response = $this->actingAs($speaker)->postJson('/api/proposals', [
            'title' => 'A perfectly valid title', 'description' => str_repeat('a', 60),
        ]);

        // The client must be able to tell this apart from a permission
        // refusal so it can prompt for the code rather than showing a dead end.
        $response->assertForbidden()->assertJsonPath('code', 'email_unverified');

        $this->actingAs($speaker)->patchJson("/api/proposals/{$proposal->id}", ['title' => 'Another valid title here'])
            ->assertForbidden();
        $this->actingAs($speaker)->deleteJson("/api/proposals/{$proposal->id}")->assertForbidden();
        $this->actingAs($speaker)->deleteJson("/api/proposals/{$proposal->id}/attachment")->assertForbidden();
    });

    it('refuses reviewing and deciding while unverified', function () {
        $proposal = Proposal::factory()->create(['status' => 'pending']);
        $reviewer = User::factory()->reviewer()->unverified()->create();
        $admin = User::factory()->admin()->unverified()->create();
        $review = Review::factory()->create(['user_id' => $reviewer->id, 'proposal_id' => $proposal->id]);

        $this->actingAs($reviewer)->postJson("/api/proposals/{$proposal->id}/reviews", ['rating' => 4])->assertForbidden();
        $this->actingAs($reviewer)->patchJson("/api/reviews/{$review->id}", ['rating' => 5])->assertForbidden();
        $this->actingAs($reviewer)->deleteJson("/api/reviews/{$review->id}")->assertForbidden();
        $this->actingAs($admin)->patchJson("/api/proposals/{$proposal->id}/status", ['status' => 'approved'])->assertForbidden();
    });

    it('tells the truth in the can object', function () {
        // A `can` of true against a gate that says no makes the client render a form
        // that cannot succeed. Each actor is eligible for exactly one gated ability, so
        // a conjunct dropped from any single one shows up here.
        $owner = User::factory()->speaker()->unverified()->create();
        $proposal = Proposal::factory()->create(['user_id' => $owner->id, 'status' => 'pending']);
        $reviewer = User::factory()->reviewer()->unverified()->create();
        $admin = User::factory()->admin()->unverified()->create();

        $this->actingAs($owner)->getJson("/api/proposals/{$proposal->id}")
            ->assertOk()->assertJsonPath('can.edit', false);
        $this->actingAs($reviewer)->getJson("/api/proposals/{$proposal->id}")
            ->assertOk()->assertJsonPath('can.review', false);
        $this->actingAs($admin)->getJson("/api/proposals/{$proposal->id}")
            ->assertOk()->assertJsonPath('can.change_status', false);
    });

    it('lets the same user write once verified', function () {
        $speaker = User::factory()->speaker()->unverified()->create();

        $speaker->markEmailAsVerified();

        $this->actingAs($speaker->fresh())->postJson('/api/proposals', [
            'title' => 'A perfectly valid title', 'description' => str_repeat('a', 60),
        ])->assertCreated();
    });

    // At Laravel's default priority, SubstituteBindings runs before `verified`: a fake
    // id fails binding and 404s, while a real-but-hidden id binds, reaches `verified`
    // and 403s — a status difference that discloses which ids are real.
    // bootstrap/app.php's prependToPriorityList moves `verified` ahead, so every id
    // gets the identical refusal below.
    it('refuses a hidden proposal and a nonexistent one identically, so an unverified outsider learns nothing about which ids exist', function () {
        $owner = User::factory()->speaker()->create();
        $hidden = Proposal::factory()->create(['user_id' => $owner->id, 'status' => 'pending']);
        $outsider = User::factory()->speaker()->unverified()->create();
        $fakeId = $hidden->id + 999_000;

        $onHidden = $this->actingAs($outsider)->patchJson("/api/proposals/{$hidden->id}", ['title' => 'Another valid title here']);
        $onFake = $this->actingAs($outsider)->patchJson("/api/proposals/{$fakeId}", ['title' => 'Another valid title here']);

        // Same status, same body, for a real id the caller cannot see
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

    // The same property on the two routes that bind a Review rather than a Proposal,
    // where a future change to binding or priority order would show first.
    it('refuses a hidden review and a nonexistent one identically, so an unverified outsider learns nothing about which review ids exist', function () {
        $author = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create(['status' => 'pending']);
        $review = Review::factory()->create(['user_id' => $author->id, 'proposal_id' => $proposal->id]);
        $outsider = User::factory()->reviewer()->unverified()->create();
        $fakeId = $review->id + 999_000;

        $onHidden = $this->actingAs($outsider)->patchJson("/api/reviews/{$review->id}", ['rating' => 5]);
        $onFake = $this->actingAs($outsider)->patchJson("/api/reviews/{$fakeId}", ['rating' => 5]);

        // Same status, same body, for someone else's review and a
        // review id that never existed.
        $onHidden->assertForbidden()->assertJsonPath('code', 'email_unverified');
        $onFake->assertForbidden()->assertExactJson($onHidden->json());

        $destroyHidden = $this->actingAs($outsider)->deleteJson("/api/reviews/{$review->id}");
        $destroyFake = $this->actingAs($outsider)->deleteJson("/api/reviews/{$fakeId}");
        $destroyHidden->assertForbidden();
        $destroyFake->assertForbidden()->assertExactJson($destroyHidden->json());
    });
});
