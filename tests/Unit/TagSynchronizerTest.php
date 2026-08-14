<?php
// tests/Unit/TagSynchronizerTest.php

use App\Models\{Proposal, Tag};
use App\Services\TagSynchronizer;

describe('tag synchronizer', function () {

    // Proposal::factory() creates its author via User::factory()->speaker(),
    // which calls assignRole() — that needs the role to already exist.
    beforeEach(fn () => $this->seed(Database\Seeders\RoleSeeder::class));

    it('attaches existing tags by id and creates new ones by name', function () {
        // Given
        $existing = Tag::create(['name' => 'Technology']);
        $proposal = Proposal::factory()->create();

        // When
        app(TagSynchronizer::class)->sync($proposal, [$existing->id, 'Observability']);

        // Then
        expect($proposal->tags()->pluck('name')->sort()->values()->all())
            ->toBe(['Observability', 'Technology']);
    });

    it('is idempotent by slug so two spellings collapse to one tag', function () {
        // Given
        Tag::create(['name' => 'Testing']);
        $proposal = Proposal::factory()->create();

        // When
        app(TagSynchronizer::class)->sync($proposal, ['testing', 'TESTING']);

        // Then
        expect(Tag::where('slug', 'testing')->count())->toBe(1)
            ->and($proposal->tags()->count())->toBe(1);
    });

    it('normalises punctuation and spacing into one slug', function () {
        // Given — case folding alone is handled by the utf8mb4_unicode_ci
        // collation, so the case-only test above would still pass with
        // Str::slug() removed. These spellings only converge because the
        // service slugifies, which makes this the load-bearing assertion.
        $proposal = Proposal::factory()->create();

        // When
        app(TagSynchronizer::class)->sync($proposal, ['Machine Learning', 'machine  learning', 'Machine-Learning!']);

        // Then
        expect(Tag::where('slug', 'machine-learning')->count())->toBe(1)
            ->and($proposal->tags()->count())->toBe(1);
    });

    it('keeps an over-long tag name within both column limits', function () {
        // Given — tag names are free text from the client. Slugging the
        // untruncated name overflows the 40-char slug column with a raw
        // QueryException rather than a graceful result.
        $proposal = Proposal::factory()->create();
        $long = str_repeat('supercalifragilistic ', 5); // 105 chars

        // When
        app(TagSynchronizer::class)->sync($proposal, [$long]);

        // Then
        $tag = Tag::sole();
        expect(strlen($tag->name))->toBeLessThanOrEqual(40)
            ->and(strlen($tag->slug))->toBeLessThanOrEqual(40)
            ->and($proposal->tags()->count())->toBe(1);
    });

    it('replaces the previous tag set rather than appending', function () {
        // Given
        $proposal = Proposal::factory()->create();
        $sync = app(TagSynchronizer::class);
        $sync->sync($proposal, ['Alpha', 'Beta']);

        // When
        $sync->sync($proposal->fresh(), ['Gamma']);

        // Then
        expect($proposal->fresh()->tags()->pluck('name')->all())->toBe(['Gamma']);
    });

    it('detaches everything when given an empty array', function () {
        // Given
        $proposal = Proposal::factory()->create();
        $sync = app(TagSynchronizer::class);
        $sync->sync($proposal, ['Alpha']);

        // When
        $sync->sync($proposal->fresh(), []);

        // Then
        expect($proposal->fresh()->tags()->count())->toBe(0);
    });

    it('drops ids that do not resolve to a real tag instead of trusting client input', function () {
        // Given
        $existing = Tag::create(['name' => 'Technology']);
        $proposal = Proposal::factory()->create();

        // When
        app(TagSynchronizer::class)->sync($proposal, [$existing->id, 999999]);

        // Then
        expect($proposal->tags()->pluck('name')->all())->toBe(['Technology']);
    });
});
