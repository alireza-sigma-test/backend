<?php

// app/Repositories/Filters/ApplySort.php

namespace App\Repositories\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;

final readonly class ApplySort
{
    public function __construct(private string $sort) {}

    public function __invoke(Builder $query, Closure $next): Builder
    {
        match ($this->sort) {
            'oldest' => $query->orderBy('proposals.created_at'),
            // MySQL has no NULLS LAST. `IS NULL` yields 0 for rated rows and 1
            // for unrated, so ordering by it ascending sinks the unrated ones.
            'rating' => $query->orderByRaw('reviews_avg_rating IS NULL, reviews_avg_rating DESC')
                ->orderByDesc('proposals.created_at'),
            default => $query->orderByDesc('proposals.created_at'),
        };

        return $next($query);
    }
}
