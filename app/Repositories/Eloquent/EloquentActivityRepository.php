<?php

namespace App\Repositories\Eloquent;

use App\Models\Proposal;
use App\Models\User;
use App\Repositories\Contracts\ActivityRepository;
use App\Repositories\Contracts\ProposalRepository;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The feed is derived from the three tables that already record durably that
 * something happened, so it cannot disagree with the data it describes.
 *
 * `proposal.updated` is deliberately absent despite being broadcast live: nothing
 * records edits, and `proposals.updated_at` moves, so the feed's own history would
 * rewrite itself under the reader.
 */
final class EloquentActivityRepository implements ActivityRepository
{
    public function __construct(private ProposalRepository $proposals) {}

    public function paginate(User $viewer, int $perPage): LengthAwarePaginator
    {
        // One scoping subquery reused by all three arms — a per-arm copy of the
        // visibility rule would drift, and the drift would be a disclosure.
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
            // fromSub, not ->union()->paginate(): the paginator's own count(*)
            // over a union counts the first arm only.
            ->fromSub($created->unionAll($decided)->unionAll($reviewed), 'activity')
            // The id is a tie-break: same-second rows would otherwise order
            // arbitrarily, repeating and skipping rows across page boundaries.
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(max(1, min($perPage, 50)))
            ->withQueryString();

        return $this->hydrate($page);
    }

    /** Two queries for the whole page, not two per row. */
    private function hydrate(LengthAwarePaginator $page): LengthAwarePaginator
    {
        $rows = collect($page->items());

        $proposals = Proposal::whereIn('id', $rows->pluck('proposal_id')->unique())->get()->keyBy('id');
        $actors = User::whereIn('id', $rows->pluck('actor_id')->unique())->get()->keyBy('id');

        return $page->setCollection($rows->map(function (object $row) use ($proposals, $actors): ?object {
            $row->proposal = $proposals->get($row->proposal_id);
            $row->actor = $actors->get($row->actor_id);

            // Query-builder rows are uncast, so this arrives as MySQL's own format.
            // It must match ActivityPayload's ISO 8601 or the client cannot render a
            // live push and a fetched row with one component.
            $row->occurred_at = Carbon::parse($row->occurred_at)->toIso8601String();

            // Unreachable via the foreign keys, but the feed is read-only and a null
            // here would be a 500 on a page that has no business failing.
            return $row->proposal && $row->actor ? $row : null;
        })->filter()->values());
    }
}
