<?php

// app/Repositories/Eloquent/EloquentActivityRepository.php

namespace App\Repositories\Eloquent;

use App\Models\Proposal;
use App\Models\User;
use App\Repositories\Contracts\ActivityRepository;
use App\Repositories\Contracts\ProposalRepository;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The feed is derived, not stored.
 *
 * Three tables already record durably that something happened — a proposal
 * row's own created_at, proposal_status_changes, and reviews — so a dedicated
 * activity table would be a denormalised copy of facts the schema already
 * holds, with a write path that can silently fall out of step with them. The
 * cost is this union; the benefit is that the feed cannot disagree with the
 * data it describes, and that soft-deleting a proposal retracts its whole
 * history from the feed with no cleanup step to forget.
 *
 * **`proposal.updated` is deliberately absent**, although it is in API.md's
 * vocabulary and is broadcast live. Nothing durably records that a proposal was
 * edited: there are audit rows for status changes and none for edits. The only
 * available proxy is `proposals.updated_at`, which cannot say what changed,
 * collapses every edit of a row into one entry, and *moves* — yesterday's edit
 * silently re-dates itself the next time the row is touched, so the feed's own
 * history would rewrite itself under the reader. Three honest sources beat four
 * where one lies about the past.
 */
final class EloquentActivityRepository implements ActivityRepository
{
    public function __construct(private ProposalRepository $proposals) {}

    public function paginate(User $viewer, int $perPage): LengthAwarePaginator
    {
        // One scoping subquery reused by all three arms. Giving each arm its
        // own copy of the visibility rule is exactly the drift this exists to
        // prevent — and here the drift would be a disclosure. Trashed proposals
        // fall out through the model's SoftDeletes global scope, so no arm
        // needs its own deleted_at condition either.
        $visible = $this->proposals->visibleQuery($viewer)->select('id')->getQuery();

        $created = DB::table('proposals')->select([
            DB::raw("CONCAT('proposal.created:', proposals.id) as id"),
            DB::raw("'proposal.created' as type"),
            'proposals.id as proposal_id',
            'proposals.user_id as actor_id',
            'proposals.created_at as occurred_at',
        ])->whereIn('proposals.id', $visible);

        $decided = DB::table('proposal_status_changes')->select([
            DB::raw("CONCAT('proposal.status_changed:', proposal_status_changes.id) as id"),
            DB::raw("'proposal.status_changed' as type"),
            'proposal_status_changes.proposal_id',
            'proposal_status_changes.changed_by as actor_id',
            'proposal_status_changes.created_at as occurred_at',
        ])->whereIn('proposal_status_changes.proposal_id', $visible);

        $reviewed = DB::table('reviews')->select([
            DB::raw("CONCAT('review.created:', reviews.id) as id"),
            DB::raw("'review.created' as type"),
            'reviews.proposal_id',
            'reviews.user_id as actor_id',
            'reviews.created_at as occurred_at',
        ])->whereIn('reviews.proposal_id', $visible);

        $page = DB::query()
            // fromSub, not ->union()->paginate(): the paginator wraps whatever
            // query it is handed in its own count(*), and wrapping a union
            // directly counts the first arm only.
            ->fromSub($created->unionAll($decided)->unionAll($reviewed), 'activity')
            // The id is a tie-break, not decoration. Two rows written in the
            // same second would otherwise order arbitrarily, and an order that
            // is unstable across page boundaries silently repeats some rows and
            // skips others.
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(max(1, min($perPage, 50)))
            ->withQueryString();

        return $this->hydrate($page);
    }

    /**
     * Attach the proposal and actor models to each row — two queries for the
     * whole page, not two per row. Shaping them is the Resource's job.
     */
    private function hydrate(LengthAwarePaginator $page): LengthAwarePaginator
    {
        $rows = collect($page->items());

        $proposals = Proposal::whereIn('id', $rows->pluck('proposal_id')->unique())->get()->keyBy('id');
        $actors = User::whereIn('id', $rows->pluck('actor_id')->unique())->get()->keyBy('id');

        return $page->setCollection($rows->map(function (object $row) use ($proposals, $actors): ?object {
            $row->proposal = $proposals->get($row->proposal_id);
            $row->actor = $actors->get($row->actor_id);

            // These rows come off the query builder, not Eloquent, so nothing
            // has cast them: occurred_at arrives as MySQL's own
            // "2026-08-16 14:32:40". The broadcast half of ActivityPayload
            // emits ISO 8601, and the two shapes have to be identical or the
            // client cannot render a live push and a fetched row with one
            // component. Pinned by ActivityTest.
            $row->occurred_at = Carbon::parse($row->occurred_at)->toIso8601String();

            // A row whose proposal or actor has vanished is dropped rather than
            // rendered half-empty. It should be unreachable — both are foreign
            // keys — but the feed is read-only and a null here would be a 500
            // on a page that has no business failing.
            return $row->proposal && $row->actor ? $row : null;
        })->filter()->values());
    }
}
