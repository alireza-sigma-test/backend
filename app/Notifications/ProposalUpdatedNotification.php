<?php

namespace App\Notifications;

/** Sent to reviewers when a speaker edits a proposal they may be reviewing. */
final class ProposalUpdatedNotification extends ProposalActivity
{
    public function type(): string
    {
        return 'proposal.updated';
    }

    protected function title(): string
    {
        return 'A proposal was updated';
    }

    protected function body(): string
    {
        return $this->quoted();
    }
}
