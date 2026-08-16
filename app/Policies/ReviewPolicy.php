<?php

// app/Policies/ReviewPolicy.php

namespace App\Policies;

use App\Enums\ProposalStatus;
use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    /** Author only, and only while the proposal has no decision yet — matches ProposalPolicy::review. */
    public function update(User $user, Review $review): bool
    {
        return $user->hasVerifiedEmail() && $user->id === $review->user_id && $this->proposalIsPending($review);
    }

    public function delete(User $user, Review $review): bool
    {
        return $user->hasVerifiedEmail() && $user->id === $review->user_id && $this->proposalIsPending($review);
    }

    /**
     * One lazy load of the parent proposal per authorize() call. Review is the
     * route-bound model here (not Proposal), so its status is never already
     * loaded. This policy is only ever invoked once per request — from
     * ReviewController::update/destroy — never inside a list/collection loop,
     * so the extra query never compounds into an N+1.
     *
     * $review->proposal can be null: reviews.proposal_id still cascades on
     * delete, but proposals now soft-delete instead of hard-deleting, so a
     * review can outlive its (trashed) parent. Deletion is one-way by design
     * — no restore path exists — so a review attached to a trashed proposal
     * is attached to something nobody can ever act on again. Treating that
     * as "not pending" denies both update and delete, which is correct: the
     * alternative (resolving the trashed parent with withTrashed()) would
     * hand out edit rights on a withdrawn talk.
     */
    private function proposalIsPending(Review $review): bool
    {
        return $review->proposal?->status === ProposalStatus::Pending;
    }
}
