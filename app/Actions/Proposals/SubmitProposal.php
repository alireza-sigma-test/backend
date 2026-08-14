<?php
// app/Actions/Proposals/SubmitProposal.php
namespace App\Actions\Proposals;

use App\Data\ProposalData;
use App\Enums\ProposalStatus;
use App\Models\{Proposal, User};
use App\Services\{AttachmentStore, TagSynchronizer};
use Illuminate\Support\Facades\DB;

final class SubmitProposal
{
    public function __construct(
        private TagSynchronizer $tags,
        private AttachmentStore $attachments,
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

            return $proposal->load(['author.roles', 'tags', 'media']);
        });
    }
}
