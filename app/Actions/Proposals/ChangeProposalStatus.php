<?php

// app/Actions/Proposals/ChangeProposalStatus.php

namespace App\Actions\Proposals;

use App\Data\StatusChangeData;
use App\Events\ProposalStatusChanged;
use App\Models\Proposal;
use App\Models\ProposalStatusChange;
use App\Models\User;
use App\Services\ActivityNotifier;
use Illuminate\Support\Facades\DB;

final class ChangeProposalStatus
{
    public function __construct(private ActivityNotifier $notifier) {}

    /**
     * @return array{proposal: Proposal, change: ?ProposalStatusChange}
     *                                                                  `change` is null for a no-op, which writes no audit row.
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

            // Only on a real decision. The no-op path above returns early
            // without an audit row, and it must not broadcast either — the
            // author would be told their proposal changed when it did not.
            ProposalStatusChanged::dispatch($proposal, $admin);
            $this->notifier->proposalStatusChanged($proposal, $admin);

            return [
                'proposal' => $proposal->fresh(['author.roles', 'tags', 'media']),
                'change' => $change,
            ];
        });
    }
}
