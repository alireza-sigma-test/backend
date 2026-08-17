<?php

namespace App\Repositories\Filters;

use App\Enums\ProposalStatus;
use Closure;
use Illuminate\Database\Eloquent\Builder;

final readonly class FilterStatus
{
    public function __construct(private ?ProposalStatus $status) {}

    public function __invoke(Builder $query, Closure $next): Builder
    {
        if ($this->status !== null) {
            $query->where('status', $this->status->value);
        }

        return $next($query);
    }
}
