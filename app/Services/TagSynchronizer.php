<?php

namespace App\Services;

use App\Models\Proposal;
use App\Models\Tag;
use Illuminate\Support\Str;

final class TagSynchronizer
{
    /**
     * Resolves a mixed list of tag ids and names, idempotent by slug across
     * sequential requests. Not concurrency-safe: firstOrCreate is
     * select-then-insert, so a simultaneous first use of the same new name loses
     * on the unique index and surfaces a QueryException rather than duplicating.
     *
     * @param  array<int, int|string>  $tags
     */
    public function sync(Proposal $proposal, array $tags): void
    {
        $ids = [];

        foreach ($tags as $tag) {
            if (is_int($tag) || ctype_digit((string) $tag)) {
                $ids[] = (int) $tag;

                continue;
            }

            // Both 40-char columns need bounding independently: Str::slug()
            // transliterates, and characters that expand to two ASCII ones (ß→ss,
            // æ→ae) let a bounded name still overflow `slug`.
            $name = Str::limit(trim((string) $tag), 40, '');
            $slug = trim(Str::limit(Str::slug($name), 40, ''), '-');

            // A pure-punctuation name slugs to '', collapsing every such tag onto one row.
            if ($name === '' || $slug === '') {
                continue;
            }

            $ids[] = Tag::firstOrCreate(['slug' => $slug], ['name' => $name])->id;
        }

        // Drop ids that do not resolve to a real tag rather than trusting input.
        $valid = Tag::whereIn('id', array_unique($ids))->pluck('id')->all();

        $proposal->tags()->sync($valid);
    }
}
