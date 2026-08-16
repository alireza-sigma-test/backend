<?php

// app/Actions/Proposals/UpdateProposal.php

namespace App\Actions\Proposals;

use App\Data\UpdateProposalData;
use App\Events\ProposalUpdated;
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
     * $actor is passed in rather than read back off $proposal->author. Today
     * the policy lets only the owner edit, so the two are the same user — but
     * the event records who did it, and re-deriving that from the row would
     * quietly credit the owner the day an admin is allowed to fix a typo.
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
                // The collection is singleFile(), so this replaces rather than
                // appends. Removal is a separate endpoint.
                $this->attachments->store($proposal, $data->attachment);
            }

            ProposalUpdated::dispatch($proposal, $actor);
            $this->notifier->proposalUpdated($proposal, $actor);

            // Unlike SubmitProposal's fresh copy, this one can have existing
            // reviews — an edited proposal is never brand new. Without these,
            // ProposalResource reads unpopulated reviews_avg_rating/reviews_count
            // and silently emits null/0, which GET /proposals/{id} would not.
            return $proposal->fresh(['author.roles', 'tags', 'media'])
                ->loadCount('reviews')
                ->loadAvg('reviews', 'rating');
        });
    }
}
