<?php
// app/Policies/ProposalPolicy.php
namespace App\Policies;

use App\Enums\{ProposalStatus, UserRole};
use App\Models\{Proposal, User};

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

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Speaker->value);
    }

    /** Owning speaker, and only while no decision exists. */
    public function update(User $user, Proposal $proposal): bool
    {
        return $this->owns($user, $proposal) && $proposal->status === ProposalStatus::Pending;
    }

    public function delete(User $user, Proposal $proposal): bool
    {
        return $user->hasRole(UserRole::Admin->value)
            || ($this->owns($user, $proposal) && $proposal->status === ProposalStatus::Pending);
    }

    /** Reviewers only, never on their own proposal, never after a decision. */
    public function review(User $user, Proposal $proposal): bool
    {
        return $user->hasRole(UserRole::Reviewer->value)
            && ! $this->owns($user, $proposal)
            && $proposal->status === ProposalStatus::Pending;
    }

    public function changeStatus(User $user, Proposal $proposal): bool
    {
        return $user->hasRole(UserRole::Admin->value);
    }

    public function viewHistory(User $user, Proposal $proposal): bool
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
