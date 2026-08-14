<?php

// app/Services/AttachmentStore.php

namespace App\Services;

use App\Models\Proposal;
use Illuminate\Http\UploadedFile;

final class AttachmentStore
{
    /**
     * Store the PDF on the private disk. The collection is declared
     * singleFile(), so Media Library replaces any previous attachment.
     */
    public function store(Proposal $proposal, UploadedFile $file): void
    {
        $proposal
            ->addMedia($file)
            ->toMediaCollection(Proposal::ATTACHMENT_COLLECTION);
    }

    public function remove(Proposal $proposal): void
    {
        $proposal->clearMediaCollection(Proposal::ATTACHMENT_COLLECTION);
    }
}
