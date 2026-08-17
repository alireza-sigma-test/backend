<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * The author's own channel and nowhere else — the one event whose audience is a single
 * person, and why `private-user.{id}` must verify identity, not just authentication.
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
