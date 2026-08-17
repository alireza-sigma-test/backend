<?php

namespace App\Repositories\Contracts;

use App\Data\ProposalFilterData;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Read surface for Proposal. Queries only — writes go through Actions. */
interface ProposalRepository
{
    public function paginate(ProposalFilterData $filters, User $viewer): LengthAwarePaginator;

    /**
     * The single definition of who may see which proposal: speakers see their own,
     * reviewers and admins see all. Returns a Builder — a deliberate Eloquent leak
     * so the activity feed can scope by subquery rather than an unbounded IN clause.
     */
    public function visibleQuery(User $viewer): Builder;

    /** @return array{all:int, pending:int, approved:int, rejected:int} */
    public function counts(User $viewer): array;

    public function findForViewer(int $id, User $viewer): Proposal;

    /**
     * Rating counts keyed "1".."max_rating", zero-filled so the client can render
     * bars without null-checking. Ratings outside the current scale are excluded
     * rather than clamped, so the bucket sum can fall short of reviews_count.
     *
     * @return array<int,int>
     */
    public function ratingDistribution(Proposal $proposal): array;

    /** Pending proposals carrying at least `review.min_reviews_to_decide` reviews. */
    public function readyToDecide(): int;

    /**
     * Viewer-unscoped, because the caller is the unauthenticated public-stats route
     * and the answer is one aggregate. Do not reuse it where a viewer exists — it
     * would span proposals they cannot see. Soft-deleted rows are excluded.
     */
    public function countCreatedInYear(int $year): int;
}
