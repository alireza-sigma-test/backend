<?php

namespace App\Actions\Proposals;

use App\Models\Proposal;
use App\Services\AttachmentStore;
use Illuminate\Support\Facades\DB;

final class DeleteProposal
{
    public function __construct(private AttachmentStore $attachments) {}

    public function handle(Proposal $proposal): void
    {
        DB::transaction(function () use ($proposal): void {
            // The only thing that removes the PDF: Media Library's cleanup listener
            // returns early for a soft-deleting model, so without this line every
            // withdrawn proposal orphans its media row and its file. It is also what
            // makes deletion one-way — any later restore path must revisit this.
            $this->attachments->remove($proposal);
            $proposal->delete();
        });
    }
}
