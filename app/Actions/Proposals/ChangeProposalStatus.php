<?php
// app/Actions/Proposals/ChangeProposalStatus.php
namespace App\Actions\Proposals;

use App\Data\StatusChangeData;
use App\Models\{Proposal, ProposalStatusChange, User};
use Illuminate\Support\Facades\DB;

final class ChangeProposalStatus
{
    /**
     * @return array{proposal: Proposal, change: ?ProposalStatusChange}
     *         `change` is null for a no-op, which writes no audit row.
     */
    public function handle(User $admin, Proposal $proposal, StatusChangeData $data): array
    {
        return DB::transaction(function () use ($admin, $proposal, $data): array {
            // Re-read under a row lock. Route-model binding loaded this instance
            // before the transaction opened, so two concurrent admins would both
            // see the same stale $from and the second would record a false prior
            // status while silently clobbering the first decision. The audit
            // trail is the only record of who decided what; it has to be right.
            $proposal = Proposal::lockForUpdate()->findOrFail($proposal->id);

            $from = $proposal->status;

            // A no-op change writes no audit row — the trail records
            // decisions, not requests.
            if ($from === $data->status) {
                return [
                    'proposal' => $proposal->load(['author.roles', 'tags', 'media']),
                    'change' => null,
                ];
            }

            $proposal->update(['status' => $data->status]);

            $change = ProposalStatusChange::create([
                'proposal_id' => $proposal->id,
                'from' => $from,
                'to' => $data->status,
                'note' => $data->note,
                'changed_by' => $admin->id,
            ]);

            return [
                'proposal' => $proposal->fresh(['author.roles', 'tags', 'media']),
                'change' => $change,
            ];
        });
    }
}
