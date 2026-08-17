<?php

namespace App\Notifications;

/**
 * The author alone. Carries the outcome in its title, so a speaker can read the result
 * off the bell without opening anything.
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
