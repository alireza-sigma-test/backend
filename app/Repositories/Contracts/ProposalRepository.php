<?php

// app/Repositories/Contracts/ProposalRepository.php

namespace App\Repositories\Contracts;

use App\Data\ProposalFilterData;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read surface for Proposal. Queries only — writes go through Actions using
 * Eloquent directly, which keeps this interface honest.
 */
interface ProposalRepository
{
    public function paginate(ProposalFilterData $filters, User $viewer): LengthAwarePaginator;

    /**
     * The viewer-scoped proposal query every read here starts from: speakers
     * see only their own, reviewers and admins see all. Soft-deleted rows drop
     * out through the model's global scope.
     *
     * Exposed so the activity feed can scope itself by subquery instead of
     * re-deriving the rule. There is one definition of who may see which
     * proposal, and a second copy of it would drift — and the drift would be a
     * disclosure, not a display bug.
     *
     * This is the one method here that hands back a Builder rather than a
     * result, which is a real leak of Eloquent into a contract. It is the
     * smaller of the two available leaks: the alternative is materialising ids
     * into an unbounded IN clause.
     */
    public function visibleQuery(User $viewer): Builder;

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
