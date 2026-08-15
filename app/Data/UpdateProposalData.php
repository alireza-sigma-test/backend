<?php

// app/Data/UpdateProposalData.php

namespace App\Data;

use Illuminate\Http\UploadedFile;

/**
 * Every field is nullable and null means "absent from the request", not
 * "clear this". The one exception is $tags, where null is absent but an
 * empty array is a deliberate "remove them all" — the Form Request draws
 * that distinction with has(), which this DTO cannot.
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
