<?php

// app/Events/ProposalUpdated.php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * A speaker edited their own proposal.
 *
 * Reviewers only, per API.md §06: an edit changes what there is to review. It
 * does not change the decision queue, so admins are not woken for it.
 */
final class ProposalUpdated extends ProposalBroadcast
{
    public function type(): string
    {
        return 'proposal.updated';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('role.reviewer')];
    }
}
