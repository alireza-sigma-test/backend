<?php

// app/Data/UpdateReviewData.php

namespace App\Data;

/** Null means "absent from the request", never "clear it". */
final readonly class UpdateReviewData
{
    public function __construct(
        public ?int $rating = null,
        public ?string $comment = null,
    ) {}
}
