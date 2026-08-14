<?php

// app/Data/ReviewData.php

namespace App\Data;

final readonly class ReviewData
{
    public function __construct(
        public int $rating,
        public ?string $comment = null,
    ) {}
}
