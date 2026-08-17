<?php

namespace App\Data;

use Illuminate\Http\UploadedFile;

final readonly class ProposalData
{
    /** @param array<int, int|string> $tags Existing tag ids or new tag names. */
    public function __construct(
        public string $title,
        public string $description,
        public array $tags = [],
        public ?UploadedFile $attachment = null,
    ) {}
}
