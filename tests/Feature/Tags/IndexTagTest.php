<?php

use App\Models\Proposal;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('tag index', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('returns tags ordered by name with a proposals count', function () {
        $reviewer = User::factory()->reviewer()->create();
        $zebra = Tag::create(['name' => 'Zebra']);
        $ants = Tag::create(['name' => 'Ants']);
        Proposal::factory()->create()->tags()->attach($zebra);

        $response = $this->actingAs($reviewer)->getJson('/api/tags');

        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'proposals_count']]])
            ->assertJsonPath('data.0.name', 'Ants')
            ->assertJsonPath('data.0.proposals_count', 0)
            ->assertJsonPath('data.1.name', 'Zebra')
            ->assertJsonPath('data.1.proposals_count', 1);
    });

    it('scopes proposals_count to the speakers own proposals', function () {
        // The same tag is used on the speaker's own proposal and on
        // another speaker's, so a naive global withCount would return 2.
        $dana = User::factory()->speaker()->create();
        $zebra = Tag::create(['name' => 'Zebra']);
        $mine = Proposal::factory()->for($dana, 'author')->create();
        $theirs = Proposal::factory()->create();
        $mine->tags()->attach($zebra);
        $theirs->tags()->attach($zebra);

        $response = $this->actingAs($dana)->getJson('/api/tags');

        // Only the speaker's own proposal is counted, matching the
        // repository's speaker-scoped list so filtering by this tag isn't empty.
        $response->assertOk()->assertJsonPath('data.0.proposals_count', 1);
    });

    it('does not scope proposals_count for a reviewer', function () {
        $reviewer = User::factory()->reviewer()->create();
        $zebra = Tag::create(['name' => 'Zebra']);
        $mine = Proposal::factory()->create();
        $theirs = Proposal::factory()->create();
        $mine->tags()->attach($zebra);
        $theirs->tags()->attach($zebra);

        $response = $this->actingAs($reviewer)->getJson('/api/tags');

        $response->assertOk()->assertJsonPath('data.0.proposals_count', 2);
    });

    it('refuses an unauthenticated request', function () {
        $this->getJson('/api/tags')->assertUnauthorized();
    });
});
