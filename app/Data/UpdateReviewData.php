<?php

namespace App\Data;

/**
 * A null $rating means "absent from the request". $comment cannot use that
 * convention, because cleared and absent would both read as null —
 * $commentProvided is the disambiguator, and when true $comment already holds
 * the value to store.
 */
final readonly class UpdateReviewData
{
    public function __construct(
        public ?int $rating = null,
        public ?string $comment = null,
        public bool $commentProvided = false,
    ) {}
}
