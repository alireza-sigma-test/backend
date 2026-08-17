<?php

namespace App\Actions\Proposals;

use App\Data\UpdateProposalData;
use App\Events\ProposalUpdated;
use App\Jobs\GenerateProposalSummary;
use App\Models\Proposal;
use App\Models\User;
use App\Services\ActivityNotifier;
use App\Services\AttachmentStore;
use App\Services\TagSynchronizer;
use Illuminate\Support\Facades\DB;

final class UpdateProposal
{
    public function __construct(
        private TagSynchronizer $tags,
        private AttachmentStore $attachments,
        private ActivityNotifier $notifier,
    ) {}

    /**
     * $actor is passed in, not read off $proposal->author: the event records who
     * acted, and re-deriving it would credit the owner the day an admin may edit.
     */
    public function handle(User $actor, Proposal $proposal, UpdateProposalData $data): Proposal
    {
        return DB::transaction(function () use ($actor, $proposal, $data): Proposal {
            $changes = [];

            if ($data->title !== null) {
                $changes['title'] = $data->title;
            }

            if ($data->description !== null) {
                $changes['description'] = $data->description;
            }

            // `status` is deliberately absent from this array and from the DTO.
            if ($changes !== []) {
                $proposal->update($changes);
            }

            if ($data->tags !== null) {
                $this->tags->sync($proposal, $data->tags);
            }

            if ($data->attachment !== null) {
                // singleFile(), so this replaces rather than appends.
                $this->attachments->store($proposal, $data->attachment);

                // Attachment changes only, not text edits — each run is a paid call.
                // The trade-off: a summary can outlive the description it describes.
                GenerateProposalSummary::for($proposal);
            }

            ProposalUpdated::dispatch($proposal, $actor);
            $this->notifier->proposalUpdated($proposal, $actor);

            // An edited proposal can already have reviews. Without these,
            // ProposalResource emits null/0 where GET /proposals/{id} would not.
            return $proposal->fresh(['author.roles', 'tags', 'media'])
                ->loadCount('reviews')
                ->loadAvg('reviews', 'rating');
        });
    }
}
