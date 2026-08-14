<?php
// app/Repositories/Filters/FilterAuthor.php
namespace App\Repositories\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;

final readonly class FilterAuthor
{
    public function __construct(private ?int $authorId) {}

    public function __invoke(Builder $query, Closure $next): Builder
    {
        if ($this->authorId !== null) {
            $query->where('user_id', $this->authorId);
        }

        return $next($query);
    }
}
