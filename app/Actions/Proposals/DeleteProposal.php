<?php

// app/Actions/Proposals/DeleteProposal.php

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
            // Clear media explicitly rather than relying on the model event:
            // Media Library's cleanup runs on `deleting`, and doing it here
            // keeps the file removal inside this transaction's boundary.
            $this->attachments->remove($proposal);
            $proposal->delete();
        });
    }
}
