<?php

namespace App\Notifications;

/** Sent to reviewers and admins when a speaker submits. */
final class ProposalCreatedNotification extends ProposalActivity
{
    public function type(): string
    {
        return 'proposal.created';
    }

    protected function title(): string
    {
        return 'New proposal to review';
    }

    protected function body(): string
    {
        return $this->quoted();
    }
}
