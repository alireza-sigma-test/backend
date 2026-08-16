<?php

// app/Actions/Proposals/SubmitProposal.php

namespace App\Actions\Proposals;

use App\Data\ProposalData;
use App\Enums\ProposalStatus;
use App\Events\ProposalCreated;
use App\Jobs\GenerateProposalSummary;
use App\Models\Proposal;
use App\Models\User;
use App\Services\ActivityNotifier;
use App\Services\AttachmentStore;
use App\Services\TagSynchronizer;
use Illuminate\Support\Facades\DB;

final class SubmitProposal
{
    public function __construct(
        private TagSynchronizer $tags,
        private AttachmentStore $attachments,
        private ActivityNotifier $notifier,
    ) {}

    public function handle(User $author, ProposalData $data): Proposal
    {
        return DB::transaction(function () use ($author, $data): Proposal {
            $proposal = Proposal::create([
                'user_id' => $author->id,
                'title' => $data->title,
                'description' => $data->description,
                // Never taken from input.
                'status' => ProposalStatus::Pending,
            ]);

            $this->tags->sync($proposal, $data->tags);

            if ($data->attachment !== null) {
                $this->attachments->store($proposal, $data->attachment);
            }

            // Safe to dispatch from inside the transaction: ProposalBroadcast
            // implements ShouldDispatchAfterCommit, so nothing leaves this
            // process until the commit succeeds. A rollback below this line
            // takes the broadcast with it.
            GenerateProposalSummary::for($proposal);

            ProposalCreated::dispatch($proposal, $author);
            $this->notifier->proposalCreated($proposal, $author);

            return $proposal->load(['author.roles', 'tags', 'media']);
        });
    }
}
