<?php

// app/Repositories/Filters/FilterTags.php

namespace App\Repositories\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;

final readonly class FilterTags
{
    /** @param array<int, string> $tags */
    public function __construct(private array $tags) {}

    public function __invoke(Builder $query, Closure $next): Builder
    {
        if ($this->tags !== []) {
            // OR semantics: a proposal matches if it carries ANY listed tag.
            $query->whereHas('tags', function (Builder $q): void {
                $q->whereIn('slug', $this->tags)
                    ->orWhereIn('tags.id', array_filter($this->tags, 'is_numeric'));
            });
        }

        return $next($query);
    }
}
