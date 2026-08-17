<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\ProposalActivity;
use App\Notifications\ProposalCreatedNotification;
use App\Notifications\ProposalStatusChangedNotification;
use App\Notifications\ProposalUpdatedNotification;
use App\Notifications\ReviewCreatedNotification;
use App\Repositories\Contracts\UserRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Durable half of the live broadcasts in app/Events/*: each method's recipient
 * list must match the matching channel's audience, or the bell and the socket
 * disagree. Sent synchronously so the writes roll back with the change they
 * describe. Nobody is ever notified of their own action.
 */
final class ActivityNotifier
{
    public function __construct(private UserRepository $users) {}

    public function proposalCreated(Proposal $proposal, User $actor): void
    {
        $this->dispatch(
            $this->holdingRoles(UserRole::Reviewer, UserRole::Admin),
            $actor,
            new ProposalCreatedNotification($proposal, $actor),
        );
    }

    public function proposalUpdated(Proposal $proposal, User $actor): void
    {
        $this->dispatch(
            $this->holdingRoles(UserRole::Reviewer),
            $actor,
            new ProposalUpdatedNotification($proposal, $actor),
        );
    }

    public function proposalStatusChanged(Proposal $proposal, User $actor): void
    {
        $this->dispatch(
            $this->author($proposal),
            $actor,
            new ProposalStatusChangedNotification($proposal, $actor),
        );
    }

    public function reviewCreated(Proposal $proposal, User $actor): void
    {
        $this->dispatch(
            $this->author($proposal)->merge($this->holdingRoles(UserRole::Admin)),
            $actor,
            new ReviewCreatedNotification($proposal, $actor),
        );
    }

    /** @param  Collection<int, User>  $recipients */
    private function dispatch(Collection $recipients, User $actor, ProposalActivity $notification): void
    {
        // unique() before reject(): review.created merges two lists, and an
        // author who is also an admin would otherwise be notified twice.
        $recipients = $recipients
            ->unique(fn (User $user) => $user->id)
            ->reject(fn (User $user) => $user->id === $actor->id);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, $notification);
    }

    /** @return Collection<int, User> */
    private function holdingRoles(UserRole ...$roles): Collection
    {
        return $this->users->withRoles(...$roles);
    }

    /**
     * Queried rather than read off the relation: a missing eager load would be a
     * null notifiable rather than a visible error.
     *
     * @return Collection<int, User>
     */
    private function author(Proposal $proposal): Collection
    {
        $author = $proposal->author()->first();

        return $author ? collect([$author]) : collect();
    }
}
