<?php

namespace App\Policies;

use App\Enums\ProposalStatus;
use App\Enums\UserRole;
use App\Models\Proposal;
use App\Models\User;

class ProposalPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // Scoping happens in the repository: speakers see only their own.
    }

    public function view(User $user, Proposal $proposal): bool
    {
        return $this->isStaff($user) || $this->owns($user, $proposal);
    }

    // The hasVerifiedEmail() conjunct on every mutating ability below keeps the `can`
    // object truthful, so the client never renders a form that 403s on submit.
    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->hasRole(UserRole::Speaker->value);
    }

    /** Owning speaker, and only while no decision exists. */
    public function update(User $user, Proposal $proposal): bool
    {
        return $user->hasVerifiedEmail()
            && $this->owns($user, $proposal) && $proposal->status === ProposalStatus::Pending;
    }

    public function delete(User $user, Proposal $proposal): bool
    {
        return $user->hasVerifiedEmail() && (
            $user->hasRole(UserRole::Admin->value)
            || ($this->owns($user, $proposal) && $proposal->status === ProposalStatus::Pending)
        );
    }

    /** Reviewers only, never on their own proposal, never after a decision. */
    public function review(User $user, Proposal $proposal): bool
    {
        return $user->hasVerifiedEmail()
            && $user->hasRole(UserRole::Reviewer->value)
            && ! $this->owns($user, $proposal)
            && $proposal->status === ProposalStatus::Pending;
    }

    public function changeStatus(User $user, Proposal $proposal): bool
    {
        return $user->hasVerifiedEmail() && $user->hasRole(UserRole::Admin->value);
    }

    /**
     * Staff only, and never the author — that exclusion is the point: a speaker who
     * could read their own summary would write for the summarizer, not the reviewer.
     */
    public function viewSummary(User $user, Proposal $proposal): bool
    {
        return $user->hasVerifiedEmail()
            && $this->isStaff($user)
            && ! $this->owns($user, $proposal);
    }

    public function viewHistory(User $user, Proposal $proposal): bool
    {
        return $user->hasRole(UserRole::Admin->value);
    }

    /** Stats aggregate across every author's proposals — admin only, same as viewHistory/changeStatus. */
    public function viewStats(User $user): bool
    {
        return $user->hasRole(UserRole::Admin->value);
    }

    private function owns(User $user, Proposal $proposal): bool
    {
        return $user->id === $proposal->user_id;
    }

    private function isStaff(User $user): bool
    {
        return $user->hasAnyRole([UserRole::Reviewer->value, UserRole::Admin->value]);
    }
}
