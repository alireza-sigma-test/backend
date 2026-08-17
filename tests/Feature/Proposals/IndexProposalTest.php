<?php

use App\Models\Proposal;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('proposal index', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('returns the paginated envelope with stable counts', function () {
        $reviewer = User::factory()->reviewer()->create();
        Proposal::factory()->count(2)->create();
        Proposal::factory()->approved()->create();

        $response = $this->actingAs($reviewer)->getJson('/api/proposals');

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
        $reviewer = User::factory()->reviewer()->create();
        Proposal::factory()->count(3)->create();

        $response = $this->actingAs($reviewer)->getJson('/api/proposals?search=zzzznomatch');

        $response->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('counts.all', 3);
    });

    it('filters by comma-separated tag slugs', function () {
        $reviewer = User::factory()->reviewer()->create();
        $tech = Tag::create(['name' => 'Technology']);
        Proposal::factory()->create()->tags()->attach($tech);
        Proposal::factory()->create();

        $response = $this->actingAs($reviewer)->getJson('/api/proposals?tags=technology');

        $response->assertOk()->assertJsonCount(1, 'data');
    });

    it('caps per_page at 50', function () {
        $reviewer = User::factory()->reviewer()->create();
        Proposal::factory()->count(3)->create();

        $response = $this->actingAs($reviewer)->getJson('/api/proposals?per_page=500');

        $response->assertOk()->assertJsonPath('meta.per_page', 50);
    });

    it('ignores author_id for a speaker, who is already scoped to their own proposals', function () {
        $dana = User::factory()->speaker()->create();
        $ilya = User::factory()->speaker()->create();
        Proposal::factory()->for($dana, 'author')->create();
        Proposal::factory()->for($ilya, 'author')->create();

        // Dana asks for ilya's author_id; the request nulls it out for
        // speakers, so the repository's own-proposals scope is untouched.
        $response = $this->actingAs($dana)->getJson("/api/proposals?author_id={$ilya->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.author.id', $dana->id);
    });

    it('honors author_id for a reviewer, since it is a real staff affordance', function () {
        $maya = User::factory()->reviewer()->create();
        $dana = User::factory()->speaker()->create();
        $ilya = User::factory()->speaker()->create();
        Proposal::factory()->for($dana, 'author')->create();
        Proposal::factory()->for($ilya, 'author')->create();

        $response = $this->actingAs($maya)->getJson("/api/proposals?author_id={$dana->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.author.id', $dana->id);
    });

    it('does not validate author_id existence for a speaker, avoiding a user-id enumeration oracle', function () {
        // A speaker's author_id is discarded in toData() regardless of
        // this request, so validating its existence would only tell an attacker
        // which ids are real users.
        $dana = User::factory()->speaker()->create();

        $this->actingAs($dana)->getJson('/api/proposals?author_id=999999')
            ->assertOk();
    });

    it('still validates author_id existence for a reviewer', function () {
        // Reviewers/admins get a real affordance, so this one must
        // still reject a nonexistent id.
        $maya = User::factory()->reviewer()->create();

        $this->actingAs($maya)->getJson('/api/proposals?author_id=999999')
            ->assertStatus(422)->assertJsonValidationErrors('author_id');
    });

    it('rejects an unknown sort value', function () {
        $reviewer = User::factory()->reviewer()->create();

        $this->actingAs($reviewer)->getJson('/api/proposals?sort=bogus')
            ->assertStatus(422)->assertJsonValidationErrors('sort');
    });

    it('refuses an unauthenticated request', function () {
        $this->getJson('/api/proposals')->assertUnauthorized();
    });
});
