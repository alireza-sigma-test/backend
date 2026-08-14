<?php

// app/Services/TagSynchronizer.php

namespace App\Services;

use App\Models\Proposal;
use App\Models\Tag;
use Illuminate\Support\Str;

final class TagSynchronizer
{
    /**
     * Resolve a mixed list of tag ids and tag names to tag ids and sync them.
     *
     * Creation is idempotent by slug across sequential requests: "testing",
     * "Testing" and "TESTING" all resolve to one row.
     *
     * This is NOT a concurrency guarantee. `firstOrCreate` is select-then-insert,
     * so two genuinely simultaneous first-time submissions of the same new name
     * can both miss the SELECT and race the INSERT; the loser hits the unique
     * index on `tags.slug` and surfaces a QueryException. That is loud rather
     * than silently duplicating, which is the right failure mode here.
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

            // Both columns are 40 chars and BOTH must be bounded independently.
            // Slugging the untruncated name overflows `slug`; but bounding only
            // the name is not enough either, because Str::slug() transliterates
            // and some characters expand to two ASCII ones (ß→ss, æ→ae, œ→oe,
            // ĳ→ij) — so a 40-character name can still yield an 80-character
            // slug. Either overflow surfaces as a raw QueryException on
            // free-text client input. Trailing dashes from cutting mid-word are
            // trimmed so the slug stays canonical.
            $name = Str::limit(trim((string) $tag), 40, '');
            $slug = trim(Str::limit(Str::slug($name), 40, ''), '-');

            // A pure-punctuation name slugs to an empty string, which would
            // otherwise collide every such tag onto one row.
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
