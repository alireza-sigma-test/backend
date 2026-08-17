<?php

namespace App\Data;

use Illuminate\Http\UploadedFile;

/**
 * null means "absent from the request", not "clear this". For $tags an empty array is
 * a deliberate "remove them all" — the Form Request draws that line with has().
 */
final readonly class UpdateProposalData
{
    /** @param array<int, int|string>|null $tags */
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?array $tags = null,
        public ?UploadedFile $attachment = null,
    ) {}
}
