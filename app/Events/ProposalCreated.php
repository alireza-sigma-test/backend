<?php

// app/Events/ProposalCreated.php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * A speaker submitted a proposal.
 *
 * Reviewers and admins both need it — reviewers to see new work arrive in the
 * list, admins to see the decision queue grow. The author is not told; they
 * just did it.
 */
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
