<?php

// tests/Feature/Proposals/UpdateProposalTest.php

use App\Models\Proposal;
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

    it('still validates the fields it is given', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

        // When / Then
        $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", ['title' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('title');
    });
});
