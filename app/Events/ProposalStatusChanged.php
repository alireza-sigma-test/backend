<?php

// app/Events/ProposalStatusChanged.php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * An admin approved or rejected a proposal.
 *
 * Goes to the author's own channel and nowhere else. This is the one event
 * whose audience is a single person, and it is why `private-user.{id}` has to
 * verify identity rather than authentication: the decision on someone's
 * proposal is theirs to hear first.
 */
final class ProposalStatusChanged extends ProposalBroadcast
{
    public function type(): string
    {
        return 'proposal.status_changed';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->proposal->user_id)];
    }
}
