<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/** Reviewers see new work arrive, admins see the queue grow. The author just acted. */
final class ProposalCreated extends ProposalBroadcast
{
    public function type(): string
    {
        return 'proposal.created';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('role.reviewer'),
            new PrivateChannel('role.admin'),
        ];
    }
}
