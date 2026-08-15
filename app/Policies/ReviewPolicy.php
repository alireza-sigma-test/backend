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
        return $user->id === $review->user_id && $this->proposalIsPending($review);
    }

    public function delete(User $user, Review $review): bool
    {
        return $user->id === $review->user_id && $this->proposalIsPending($review);
    }

    /**
     * One lazy load of the parent proposal per authorize() call. Review is the
     * route-bound model here (not Proposal), so its status is never already
     * loaded. This policy is only ever invoked once per request — from
     * ReviewController::update/destroy — never inside a list/collection loop,
     * so the extra query never compounds into an N+1.
     */
    private function proposalIsPending(Review $review): bool
    {
        return $review->proposal->status === ProposalStatus::Pending;
    }
}
