<?php
// tests/Feature/Tags/IndexTagTest.php

use App\Models\{Proposal, Tag, User};

describe('tag index', function () {

    beforeEach(fn () => $this->seed(Database\Seeders\RoleSeeder::class));

    it('returns tags ordered by name with a proposals count', function () {
        // Given
        $reviewer = User::factory()->reviewer()->create();
        $zebra = Tag::create(['name' => 'Zebra']);
        $ants = Tag::create(['name' => 'Ants']);
        Proposal::factory()->create()->tags()->attach($zebra);

        // When
        $response = $this->actingAs($reviewer)->getJson('/api/tags');

        // Then
        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'proposals_count']]])
            ->assertJsonPath('data.0.name', 'Ants')
            ->assertJsonPath('data.0.proposals_count', 0)
            ->assertJsonPath('data.1.name', 'Zebra')
            ->assertJsonPath('data.1.proposals_count', 1);
    });

    it('refuses an unauthenticated request', function () {
        $this->getJson('/api/tags')->assertUnauthorized();
    });
});
