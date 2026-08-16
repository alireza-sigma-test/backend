<?php

// app/Actions/Proposals/RemoveAttachment.php

namespace App\Actions\Proposals;

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
        });
    }
}
