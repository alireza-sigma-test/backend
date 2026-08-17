<?php

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
     * One lazy load per authorize() call — invoked once per request, never in a loop,
     * so it never becomes an N+1.
     *
     * `?->` is load-bearing: a soft-deleted proposal makes this null, and treating
     * that as "not pending" denies both abilities. Resolving it with withTrashed()
     * would hand out edit rights on a withdrawn talk.
     */
    private function proposalIsPending(Review $review): bool
    {
        return $review->proposal?->status === ProposalStatus::Pending;
    }
}
