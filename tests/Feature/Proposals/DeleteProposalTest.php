<?php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('deleting a proposal', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('lets the owning speaker delete it while pending', function () {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

        $this->actingAs($dana)->deleteJson("/api/proposals/{$proposal->id}")->assertNoContent();
        $this->assertSoftDeleted('proposals', ['id' => $proposal->id]);
    });

    it('refuses the owning speaker once a decision exists', function () {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'approved']);

        $this->actingAs($dana)->deleteJson("/api/proposals/{$proposal->id}")->assertForbidden();
        // assertNotSoftDeleted, not assertDatabaseHas: once the trait landed,
        // the row surviving stopped meaning the proposal survived.
        $this->assertNotSoftDeleted('proposals', ['id' => $proposal->id]);
    });

    it('lets an admin delete a decided proposal', function () {
        $proposal = Proposal::factory()->create(['status' => 'approved']);

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson("/api/proposals/{$proposal->id}")->assertNoContent();
        $this->assertSoftDeleted('proposals', ['id' => $proposal->id]);
    });

    it('leaves the reviews behind', function () {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        Review::factory()->count(2)->create(['proposal_id' => $proposal->id]);

        $this->actingAs($dana)->deleteJson("/api/proposals/{$proposal->id}")->assertNoContent();

        // Reviews declare cascadeOnDelete against proposals, but this
        // delete is a soft delete now, not a hard one, so the reviews survive
        // it. Losing them here would break every average that follows.
        expect(Review::where('proposal_id', $proposal->id)->count())->toBe(2);
    });

    it('removes the attachment without touching the proposal', function () {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        $proposal->addMedia(fakePdf('slides.pdf'))
            ->toMediaCollection(Proposal::ATTACHMENT_COLLECTION);
        expect($proposal->fresh()->attachment())->not->toBeNull();

        $this->actingAs($dana)->deleteJson("/api/proposals/{$proposal->id}/attachment")->assertNoContent();

        expect($proposal->fresh()->attachment())->toBeNull();
        $this->assertDatabaseHas('proposals', ['id' => $proposal->id]);
    });

    it('404s rather than 403s for a non-owner', function () {
        $proposal = Proposal::factory()->create(['status' => 'pending']);

        $this->actingAs(User::factory()->speaker()->create())
            ->deleteJson("/api/proposals/{$proposal->id}")->assertNotFound();
    });
});
