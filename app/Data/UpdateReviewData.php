<?php

// app/Data/UpdateReviewData.php

namespace App\Data;

/**
 * $rating: null means "absent from the request" — leave the rating alone.
 *
 * $comment cannot use that same convention on its own: once cleared and
 * absent both need to read as null, a plain nullable string can no longer
 * tell them apart. $commentProvided is the disambiguator: false means the
 * comment key was absent from the request and $comment must be ignored;
 * true means the client sent it — as text, an explicit null, or "" — and
 * $comment already holds the value to store (an explicit null or ""
 * collapses to null here too, matching how the create path treats a blank
 * comment).
 */
final readonly class UpdateReviewData
{
    public function __construct(
        public ?int $rating = null,
        public ?string $comment = null,
        public bool $commentProvided = false,
    ) {}
}
