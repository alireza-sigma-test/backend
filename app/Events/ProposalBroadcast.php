<?php

namespace App\Events;

use App\Models\Proposal;
use App\Models\User;
use App\Support\ActivityPayload;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Shared shape for the four proposal/review broadcasts (docs/design/API.md §06).
 *
 * The thin payload is a security property: a private channel is authorized once at
 * subscribe time, so every field here reaches every subscriber on it. Subclasses
 * choose channels and a type; none may widen the payload. Clients that need more
 * refetch. EventPayloadTest pins the key set.
 *
 * ShouldDispatchAfterCommit, because the writes live inside DB::transaction() and a
 * rollback would otherwise broadcast a proposal that never existed.
 */
abstract class ProposalBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $occurredAt;

    public function __construct(
        public readonly Proposal $proposal,
        public readonly User $actor,
    ) {
        // Stamped when it happened, not when the queued worker gets to it.
        $this->occurredAt = now()->toIso8601String();
    }

    /** The event vocabulary from API.md §06. Also the name on the wire. */
    abstract public function type(): string;

    /** @return array<int, Channel> */
    abstract public function broadcastOn(): array;

    public function broadcastAs(): string
    {
        return $this->type();
    }

    /** @return array<string, mixed> */
    final public function broadcastWith(): array
    {
        return ActivityPayload::make($this->type(), $this->proposal, $this->actor, $this->occurredAt);
    }
}
