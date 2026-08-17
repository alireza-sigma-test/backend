<?php

namespace App\Data;

use App\Enums\ProposalStatus;

final readonly class ProposalFilterData
{
    /** @param array<int, string> $tags Tag slugs or numeric ids. */
    public function __construct(
        public ?string $search = null,
        public array $tags = [],
        public ?ProposalStatus $status = null,
        public ?int $authorId = null,
        public string $sort = 'newest',
        public int $perPage = 15,
    ) {}
}
