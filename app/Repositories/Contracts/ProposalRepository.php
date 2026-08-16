<?php

// app/Repositories/Contracts/ProposalRepository.php

namespace App\Repositories\Contracts;

use App\Data\ProposalFilterData;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read surface for Proposal. Queries only — writes go through Actions using
 * Eloquent directly, which keeps this interface honest.
 */
interface ProposalRepository
{
    public function paginate(ProposalFilterData $filters, User $viewer): LengthAwarePaginator;

    /** @return array{all:int, pending:int, approved:int, rejected:int} */
    public function counts(User $viewer): array;

    public function findForViewer(int $id, User $viewer): Proposal;

    /**
     * Rating counts keyed "1".."max_rating", every bucket present and
     * zero-filled so the client can render bars without null-checking.
     *
     * Ratings outside the current 1..max_rating scale (left behind if
     * max_rating is later lowered below ratings that already exist) are
     * excluded, not clamped into the nearest bucket — clamping would report
     * a genuine score as something it is not. That means the bucket sum
     * equals reviews_count only when every stored rating falls within the
     * current scale; a lowered scale is an unsupported data migration, and
     * under-counting the total here is the honest failure mode.
     *
     * @return array<int,int>
     */
    public function ratingDistribution(Proposal $proposal): array;

    /** Pending proposals carrying at least `review.min_reviews_to_decide` reviews. */
    public function readyToDecide(): int;

    /**
     * Proposals created in the given calendar year. Feeds the public stats
     * endpoint.
     *
     * Soft-deleted proposals are excluded — the endpoint is unauthenticated,
     * so a withdrawn proposal leaking back into this count is a disclosure,
     * not just an off-by-one.
     *
     * Deliberately viewer-unscoped, unlike every method above except
     * readyToDecide(): the only caller is a route with no authenticated user
     * to scope by, and the answer is a single aggregate over all proposals
     * that discloses nothing about any one of them. Reusing it on a surface
     * where a viewer DOES exist would hand that caller a count spanning
     * proposals they cannot see.
     */
    public function countCreatedInYear(int $year): int;
}
