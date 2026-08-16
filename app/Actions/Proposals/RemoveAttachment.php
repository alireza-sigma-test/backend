<?php

// app/Actions/Proposals/RemoveAttachment.php

namespace App\Actions\Proposals;

use App\Jobs\GenerateProposalSummary;
use App\Models\Proposal;
use App\Services\AttachmentStore;
use Illuminate\Support\Facades\DB;

final class RemoveAttachment
{
    public function __construct(private AttachmentStore $attachments) {}

    public function handle(Proposal $proposal): void
    {
        DB::transaction(function () use ($proposal): void {
            $this->attachments->remove($proposal);

            // Removing the deck is an attachment change like any other, and
            // the one where a stale summary is most misleading: it would go on
            // describing slides that are no longer attached to anything.
            GenerateProposalSummary::for($proposal);
        });
    }
}
