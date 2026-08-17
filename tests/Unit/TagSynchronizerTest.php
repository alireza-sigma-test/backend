<?php

use App\Models\Proposal;
use App\Models\Tag;
use App\Services\TagSynchronizer;
use Database\Seeders\RoleSeeder;

describe('tag synchronizer', function () {

    // Proposal::factory() creates its author via User::factory()->speaker(),
    // which calls assignRole() — that needs the role to already exist.
    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('attaches existing tags by id and creates new ones by name', function () {
        $existing = Tag::create(['name' => 'Technology']);
        $proposal = Proposal::factory()->create();

        app(TagSynchronizer::class)->sync($proposal, [$existing->id, 'Observability']);

        expect($proposal->tags()->pluck('name')->sort()->values()->all())
            ->toBe(['Observability', 'Technology']);
    });

    it('is idempotent by slug so two spellings collapse to one tag', function () {
        Tag::create(['name' => 'Testing']);
        $proposal = Proposal::factory()->create();

        app(TagSynchronizer::class)->sync($proposal, ['testing', 'TESTING']);

        expect(Tag::where('slug', 'testing')->count())->toBe(1)
            ->and($proposal->tags()->count())->toBe(1);
    });

    it('normalises punctuation and spacing into one slug', function () {
        // The utf8mb4_unicode_ci collation handles case folding, so the test above
        // would pass with Str::slug() removed. These spellings converge only because
        // the service slugifies.
        $proposal = Proposal::factory()->create();

        app(TagSynchronizer::class)->sync($proposal, ['Machine Learning', 'machine  learning', 'Machine-Learning!']);

        expect(Tag::where('slug', 'machine-learning')->count())->toBe(1)
            ->and($proposal->tags()->count())->toBe(1);
    });

    it('keeps a transliterating name within the slug column limit', function () {
        // Str::slug() expands ß to "ss", so 40 of them slug to 80 chars
        // and overflow the column unless the slug is bounded in its own right.
        $proposal = Proposal::factory()->create();

        app(TagSynchronizer::class)->sync($proposal, [str_repeat('ß', 40)]);

        $tag = Tag::sole();
        expect(strlen($tag->slug))->toBeLessThanOrEqual(40)
            ->and($proposal->tags()->count())->toBe(1);
    });

    it('keeps an over-long tag name within both column limits', function () {
        // Tag names are free text from the client. Slugging the
        // untruncated name overflows the 40-char slug column with a raw
        // QueryException rather than a graceful result.
        $proposal = Proposal::factory()->create();
        $long = str_repeat('supercalifragilistic ', 5); // 105 chars

        app(TagSynchronizer::class)->sync($proposal, [$long]);

        $tag = Tag::sole();
        expect(strlen($tag->name))->toBeLessThanOrEqual(40)
            ->and(strlen($tag->slug))->toBeLessThanOrEqual(40)
            ->and($proposal->tags()->count())->toBe(1);
    });

    it('replaces the previous tag set rather than appending', function () {
        $proposal = Proposal::factory()->create();
        $sync = app(TagSynchronizer::class);
        $sync->sync($proposal, ['Alpha', 'Beta']);

        $sync->sync($proposal->fresh(), ['Gamma']);

        expect($proposal->fresh()->tags()->pluck('name')->all())->toBe(['Gamma']);
    });

    it('detaches everything when given an empty array', function () {
        $proposal = Proposal::factory()->create();
        $sync = app(TagSynchronizer::class);
        $sync->sync($proposal, ['Alpha']);

        $sync->sync($proposal->fresh(), []);

        expect($proposal->fresh()->tags()->count())->toBe(0);
    });

    it('drops ids that do not resolve to a real tag instead of trusting client input', function () {
        $existing = Tag::create(['name' => 'Technology']);
        $proposal = Proposal::factory()->create();

        app(TagSynchronizer::class)->sync($proposal, [$existing->id, 999999]);

        expect($proposal->tags()->pluck('name')->all())->toBe(['Technology']);
    });
});
