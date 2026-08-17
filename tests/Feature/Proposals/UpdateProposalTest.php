<?php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\Tag;
use App\Models\User;
use App\Services\AttachmentStore;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;

describe('updating a proposal', function () {

    beforeEach(function () {
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    });

    it('changes only the fields the owner sends', function () {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create([
            'user_id' => $dana->id, 'status' => 'pending',
            'title' => 'The original title here', 'description' => str_repeat('a', 60),
        ]);

        $response = $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", [
            'title' => 'A rewritten and better title',
        ]);

        $response->assertOk()->assertJsonPath('title', 'A rewritten and better title');
        expect($proposal->fresh()->description)->toBe(str_repeat('a', 60));
    });

    it('replaces the tag set when tags are sent', function () {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        $existing = Tag::factory()->create();
        $proposal->tags()->attach($existing);

        $response = $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", [
            'tags' => ['observability'],
        ]);

        $response->assertOk();
        expect($proposal->fresh()->tags->pluck('name')->all())->toBe(['observability']);
    });

    it('clears every tag when tags is sent as an empty array', function () {
        // has() vs input('tags', []): an explicit `[]` must reach the action as "clear
        // them", distinct from an absent key. A regression to input('tags', null) would
        // pass every other tag test here and break this one.
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        $proposal->tags()->attach(Tag::factory()->count(2)->create());

        $response = $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", ['tags' => []]);

        $response->assertOk();
        expect($proposal->fresh()->tags)->toBeEmpty();
    });

    it('leaves tags untouched when the field is absent', function () {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        $proposal->tags()->attach(Tag::factory()->create(['name' => 'testing']));

        $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", ['title' => 'Another title entirely'])
            ->assertOk();

        expect($proposal->fresh()->tags->pluck('name')->all())->toBe(['testing']);
    });

    it('never lets the client set status', function () {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

        $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", [
            'title' => 'Trying to self approve', 'status' => 'approved',
        ])->assertOk();

        expect($proposal->fresh()->status->value)->toBe('pending');
    });

    it('refuses once a decision exists', function () {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'approved']);

        $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", ['title' => 'Too late to edit this'])
            ->assertForbidden();
    });

    it('404s for a speaker who does not own it, disclosing nothing', function () {
        $proposal = Proposal::factory()->create(['status' => 'pending']);

        // 404 and not 403, so a real id is indistinguishable
        // from a fake one.
        $this->actingAs(User::factory()->speaker()->create())
            ->patchJson("/api/proposals/{$proposal->id}", ['title' => 'Not mine to edit here'])
            ->assertNotFound();
        $this->actingAs(User::factory()->speaker()->create())
            ->patchJson('/api/proposals/999999', ['title' => 'Does not exist at all'])
            ->assertNotFound();
    });

    it('returns the same review aggregates a follow-up GET would, not stale zeros', function () {
        // A proposal that already has reviews. This is what tells this
        // case apart from creation: a brand new proposal genuinely has none,
        // but an edited one is never brand new.
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        Review::factory()->count(3)->create(['proposal_id' => $proposal->id, 'rating' => 4]);

        $patched = $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", [
            'title' => 'A rewritten title, reviews already in place',
        ]);
        $fetched = $this->actingAs($dana)->getJson("/api/proposals/{$proposal->id}");

        // PATCH must return the same aggregates GET would, not an unpopulated 0/null
        // pair a client would merge in and render as "no reviews".
        $patched->assertOk()
            ->assertJsonPath('reviews_count', 3)
            ->assertJsonPath('average_rating', 4);
        expect($patched->json('reviews_count'))->toBe($fetched->json('reviews_count'))
            ->and($patched->json('average_rating'))->toBe($fetched->json('average_rating'));
    });

    it('still validates the fields it is given', function () {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

        $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", ['title' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('title');
    });

    it('rejects an explicit null attachment rather than silently keeping the old one', function () {
        // UpdateProposal only acts on a non-null attachment, so `{"attachment": null}`
        // used to 200 and change nothing — a silent no-op read as a successful clear.
        // Clearing belongs to DELETE /proposals/{id}/attachment.
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        app(AttachmentStore::class)->store($proposal, fakePdf('outline.pdf'));

        $response = $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", ['attachment' => null]);

        $response->assertStatus(422)->assertJsonValidationErrors('attachment');
        expect($proposal->fresh()->attachment())->not->toBeNull();
    });

    it('replaces the attachment through PATCH multipart, not just at creation', function () {
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        app(AttachmentStore::class)->store($proposal, fakePdf('original.pdf'));

        $response = $this->actingAs($dana)->patch("/api/proposals/{$proposal->id}", [
            'attachment' => fakePdf('replacement.pdf'),
        ]);

        $response->assertOk();
        $fresh = $proposal->fresh();
        expect($fresh->getMedia(Proposal::ATTACHMENT_COLLECTION))->toHaveCount(1)
            ->and($fresh->attachment()->file_name)->toBe('replacement.pdf');
    });
});
