<?php

// app/Actions/Proposals/UpdateProposal.php

namespace App\Actions\Proposals;

use App\Data\UpdateProposalData;
use App\Models\Proposal;
use App\Services\AttachmentStore;
use App\Services\TagSynchronizer;
use Illuminate\Support\Facades\DB;

final class UpdateProposal
{
    public function __construct(
        private TagSynchronizer $tags,
        private AttachmentStore $attachments,
    ) {}

    public function handle(Proposal $proposal, UpdateProposalData $data): Proposal
    {
        return DB::transaction(function () use ($proposal, $data): Proposal {
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

            return $proposal->fresh(['author.roles', 'tags', 'media']);
        });
    }
}
