<?php

// app/Services/ActivityNotifier.php

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
 * Decides who hears about what, in one place.
 *
 * **The recipient list of each method is the audience of the matching broadcast
 * channel, made durable.** app/Events/* pushes to a live socket; this writes
 * the same fact to the notifications table so it survives a closed tab and a
 * stopped Reverb. Deriving the two from different rules would let the bell and
 * the socket disagree about what happened, and the disagreement would show up
 * as notifications that never arrive live or live events with no record.
 *
 *   proposal.created        → reviewers + admins   (role.reviewer, role.admin)
 *   proposal.updated        → reviewers            (role.reviewer)
 *   proposal.status_changed → the author           (user.{author})
 *   review.created          → the author + admins  (user.{author}, role.admin)
 *
 * Nobody is ever notified of their own action. An admin who approves a proposal
 * does not need telling that a proposal was approved, and an admin who submits
 * one is on the admin list *and* is the actor — the actor filter wins.
 *
 * Sent synchronously rather than queued. The recipient lists here are a handful
 * of rows, the writes belong to the same transaction as the change they
 * describe (so a rollback takes them with it), and a queued notification would
 * put the bell's correctness behind the worker's availability.
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
        // unique() before reject(): the author of a proposal can also be an
        // admin, and review.created merges two lists that would then notify
        // them twice about the same review.
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
     * The author is read through the relation rather than assumed loaded — the
     * Actions hand over a freshly locked or freshly created row, and a missing
     * eager load here would be a null notifiable rather than a visible error.
     *
     * @return Collection<int, User>
     */
    private function author(Proposal $proposal): Collection
    {
        $author = $proposal->author()->first();

        return $author ? collect([$author]) : collect();
    }
}
