<?php

// app/Repositories/Filters/SearchTitle.php

namespace App\Repositories\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;

final readonly class SearchTitle
{
    public function __construct(private ?string $search) {}

    public function __invoke(Builder $query, Closure $next): Builder
    {
        $term = trim((string) $this->search);

        if ($term !== '') {
            // Bound parameter, never string concatenation. LIKE is already
            // case-insensitive under utf8mb4_unicode_ci, which is the collation
            // config/database.php sets for every table.
            $query->where('title', 'LIKE', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%');
        }

        return $next($query);
    }
}
