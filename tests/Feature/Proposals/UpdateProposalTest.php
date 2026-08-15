<?php

// tests/Feature/Proposals/UpdateProposalTest.php

use App\Models\Proposal;
use App\Models\Review;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('updating a proposal', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('changes only the fields the owner sends', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create([
            'user_id' => $dana->id, 'status' => 'pending',
            'title' => 'The original title here', 'description' => str_repeat('a', 60),
        ]);

        // When
        $response = $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", [
            'title' => 'A rewritten and better title',
        ]);

        // Then
        $response->assertOk()->assertJsonPath('title', 'A rewritten and better title');
        expect($proposal->fresh()->description)->toBe(str_repeat('a', 60));
    });

    it('replaces the tag set when tags are sent', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        $existing = Tag::factory()->create();
        $proposal->tags()->attach($existing);

        // When
        $response = $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", [
            'tags' => ['observability'],
        ]);

        // Then
        $response->assertOk();
        expect($proposal->fresh()->tags->pluck('name')->all())->toBe(['observability']);
    });

    it('leaves tags untouched when the field is absent', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        $proposal->tags()->attach(Tag::factory()->create(['name' => 'testing']));

        // When
        $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", ['title' => 'Another title entirely'])
            ->assertOk();

        // Then
        expect($proposal->fresh()->tags->pluck('name')->all())->toBe(['testing']);
    });

    it('never lets the client set status', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

        // When
        $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", [
            'title' => 'Trying to self approve', 'status' => 'approved',
        ])->assertOk();

        // Then
        expect($proposal->fresh()->status->value)->toBe('pending');
    });

    it('refuses once a decision exists', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'approved']);

        // When / Then
        $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", ['title' => 'Too late to edit this'])
            ->assertForbidden();
    });

    it('404s for a speaker who does not own it, disclosing nothing', function () {
        // Given
        $proposal = Proposal::factory()->create(['status' => 'pending']);

        // When / Then — 404 and not 403, so a real id is indistinguishable
        // from a fake one.
        $this->actingAs(User::factory()->speaker()->create())
            ->patchJson("/api/proposals/{$proposal->id}", ['title' => 'Not mine to edit here'])
            ->assertNotFound();
        $this->actingAs(User::factory()->speaker()->create())
            ->patchJson('/api/proposals/999999', ['title' => 'Does not exist at all'])
            ->assertNotFound();
    });

    it('returns the same review aggregates a follow-up GET would, not stale zeros', function () {
        // Given — a proposal that already has reviews. This is what tells this
        // case apart from creation: a brand new proposal genuinely has none,
        // but an edited one is never brand new.
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
        Review::factory()->count(3)->create(['proposal_id' => $proposal->id, 'rating' => 4]);

        // When
        $patched = $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", [
            'title' => 'A rewritten title, reviews already in place',
        ]);
        $fetched = $this->actingAs($dana)->getJson("/api/proposals/{$proposal->id}");

        // Then — the PATCH response must carry the real aggregates, matching
        // what GET returns for the same proposal at the same moment, not an
        // unpopulated 0/null pair a client would merge into its store after
        // an edit and render as "no reviews".
        $patched->assertOk()
            ->assertJsonPath('reviews_count', 3)
            ->assertJsonPath('average_rating', 4);
        expect($patched->json('reviews_count'))->toBe($fetched->json('reviews_count'))
            ->and($patched->json('average_rating'))->toBe($fetched->json('average_rating'));
    });

    it('still validates the fields it is given', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

        // When / Then
        $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", ['title' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('title');
    });
});
