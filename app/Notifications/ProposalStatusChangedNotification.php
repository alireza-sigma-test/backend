<?php

// app/Notifications/ProposalStatusChangedNotification.php

namespace App\Notifications;

/**
 * Sent to the proposal's author when an admin decides.
 *
 * The only one of the four whose audience is a single person, and the one that
 * carries the outcome in its title — a speaker should be able to read the
 * result off the bell without opening anything.
 */
final class ProposalStatusChangedNotification extends ProposalActivity
{
    public function type(): string
    {
        return 'proposal.status_changed';
    }

    protected function title(): string
    {
        return 'Your proposal was '.$this->proposal->status->value;
    }

    protected function body(): string
    {
        return $this->quoted();
    }
}
