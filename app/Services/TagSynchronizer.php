<?php
// app/Services/TagSynchronizer.php
namespace App\Services;

use App\Models\{Proposal, Tag};
use Illuminate\Support\Str;

final class TagSynchronizer
{
    /**
     * Resolve a mixed list of tag ids and tag names to tag ids and sync them.
     *
     * Creation is idempotent by slug: two speakers typing "testing" and
     * "Testing" at the same moment converge on one tag.
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

            $name = trim((string) $tag);

            if ($name === '') {
                continue;
            }

            $ids[] = Tag::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => Str::limit($name, 40, '')],
            )->id;
        }

        // Drop ids that do not resolve to a real tag rather than trusting input.
        $valid = Tag::whereIn('id', array_unique($ids))->pluck('id')->all();

        $proposal->tags()->sync($valid);
    }
}
