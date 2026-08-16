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
            // The ONLY thing that removes the PDF now — not a belt-and-braces
            // duplicate of the model event. Media Library's cleanup listener
            // (InteractsWithMedia::bootInteractsWithMedia) runs on `deleting`,
            // but returns early when the model uses SoftDeletes and is not
            // force-deleting — which is every delete Proposal performs. Drop
            // this line and each withdrawn proposal orphans its media row and
            // its file on disk. Doing it here also keeps the file removal
            // inside this transaction's boundary, and it is what makes
            // deletion one-way: a later tier adding restore must revisit
            // exactly this line, because the file is already gone.
            $this->attachments->remove($proposal);
            $proposal->delete();
        });
    }
}
