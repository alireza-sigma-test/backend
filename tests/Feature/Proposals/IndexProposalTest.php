<?php
// tests/Feature/Proposals/IndexProposalTest.php

use App\Models\{Proposal, Tag, User};

describe('proposal index', function () {

    beforeEach(fn () => $this->seed(Database\Seeders\RoleSeeder::class));

    it('returns the paginated envelope with stable counts', function () {
        // Given
        $reviewer = User::factory()->reviewer()->create();
        Proposal::factory()->count(2)->create();
        Proposal::factory()->approved()->create();

        // When
        $response = $this->actingAs($reviewer)->getJson('/api/proposals');

        // Then
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'ref', 'title', 'status', 'tags', 'author', 'can']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'counts' => ['all', 'pending', 'approved', 'rejected'],
            ])
            ->assertJsonPath('counts.all', 3)
            ->assertJsonPath('counts.approved', 1);
    });

    it('leaves counts unchanged when a search filters everything out', function () {
        // Given
        $reviewer = User::factory()->reviewer()->create();
        Proposal::factory()->count(3)->create();

        // When
        $response = $this->actingAs($reviewer)->getJson('/api/proposals?search=zzzznomatch');

        // Then
        $response->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('counts.all', 3);
    });

    it('filters by comma-separated tag slugs', function () {
        // Given
        $reviewer = User::factory()->reviewer()->create();
        $tech = Tag::create(['name' => 'Technology']);
        Proposal::factory()->create()->tags()->attach($tech);
        Proposal::factory()->create();

        // When
        $response = $this->actingAs($reviewer)->getJson('/api/proposals?tags=technology');

        // Then
        $response->assertOk()->assertJsonCount(1, 'data');
    });

    it('caps per_page at 50', function () {
        // Given
        $reviewer = User::factory()->reviewer()->create();
        Proposal::factory()->count(3)->create();

        // When
        $response = $this->actingAs($reviewer)->getJson('/api/proposals?per_page=500');

        // Then
        $response->assertOk()->assertJsonPath('meta.per_page', 50);
    });

    it('ignores author_id for a speaker, who is already scoped to their own proposals', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $ilya = User::factory()->speaker()->create();
        Proposal::factory()->for($dana, 'author')->create();
        Proposal::factory()->for($ilya, 'author')->create();

        // When — dana asks for ilya's author_id; the request nulls it out for
        // speakers, so the repository's own-proposals scope is untouched.
        $response = $this->actingAs($dana)->getJson("/api/proposals?author_id={$ilya->id}");

        // Then — dana still sees only her own proposal, never ilya's.
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.author.id', $dana->id);
    });

    it('honors author_id for a reviewer, since it is a real staff affordance', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $dana = User::factory()->speaker()->create();
        $ilya = User::factory()->speaker()->create();
        Proposal::factory()->for($dana, 'author')->create();
        Proposal::factory()->for($ilya, 'author')->create();

        // When
        $response = $this->actingAs($maya)->getJson("/api/proposals?author_id={$dana->id}");

        // Then
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.author.id', $dana->id);
    });

    it('does not validate author_id existence for a speaker, avoiding a user-id enumeration oracle', function () {
        // Given — a speaker's author_id is discarded in toData() regardless of
        // this request, so validating its existence would only tell an attacker
        // which ids are real users.
        $dana = User::factory()->speaker()->create();

        // When / Then — a nonexistent id must not 422.
        $this->actingAs($dana)->getJson('/api/proposals?author_id=999999')
            ->assertOk();
    });

    it('still validates author_id existence for a reviewer', function () {
        // Given — reviewers/admins get a real affordance, so this one must
        // still reject a nonexistent id.
        $maya = User::factory()->reviewer()->create();

        // When / Then
        $this->actingAs($maya)->getJson('/api/proposals?author_id=999999')
            ->assertStatus(422)->assertJsonValidationErrors('author_id');
    });

    it('rejects an unknown sort value', function () {
        // Given
        $reviewer = User::factory()->reviewer()->create();

        // When / Then
        $this->actingAs($reviewer)->getJson('/api/proposals?sort=bogus')
            ->assertStatus(422)->assertJsonValidationErrors('sort');
    });

    it('refuses an unauthenticated request', function () {
        $this->getJson('/api/proposals')->assertUnauthorized();
    });
});
