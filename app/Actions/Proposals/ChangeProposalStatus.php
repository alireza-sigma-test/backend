<?php
// app/Actions/Proposals/ChangeProposalStatus.php
namespace App\Actions\Proposals;

use App\Data\StatusChangeData;
use App\Models\{Proposal, ProposalStatusChange, User};
use Illuminate\Support\Facades\DB;

final class ChangeProposalStatus
{
    public function handle(User $admin, Proposal $proposal, StatusChangeData $data): Proposal
    {
        return DB::transaction(function () use ($admin, $proposal, $data): Proposal {
            $from = $proposal->status;

            // A no-op change writes no audit row — the trail records
            // decisions, not requests.
            if ($from === $data->status) {
                return $proposal->load(['author.roles', 'tags', 'media']);
            }

            $proposal->update(['status' => $data->status]);

            ProposalStatusChange::create([
                'proposal_id' => $proposal->id,
                'from' => $from,
                'to' => $data->status,
                'note' => $data->note,
                'changed_by' => $admin->id,
            ]);

            return $proposal->fresh(['author.roles', 'tags', 'media']);
        });
    }
}
