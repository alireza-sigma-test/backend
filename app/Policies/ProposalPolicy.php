<?php

// app/Policies/ProposalPolicy.php

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

    // Verification also gates every mutating ability below. This keeps the
    // `can` object truthful — otherwise the client renders a form (e.g. a
    // review) that 403s on submit — even though the route-level `verified`
    // middleware already refuses the request first; see EnsureEmailIsVerified.
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
     * The AI summary is a reading aid for the people evaluating a proposal —
     * verified reviewers and admins. **Never the author**, and that is the
     * point of the ability rather than an incidental consequence: a speaker
     * who could read the summary of their own proposal would start writing
     * for the summarizer instead of for the reviewer.
     *
     * Verified, like every other ability here that grants a real capability.
     * An unverified reviewer has cleared no gate yet, and this one is not the
     * exception.
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
