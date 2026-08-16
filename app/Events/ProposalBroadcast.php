<?php

// app/Events/ProposalBroadcast.php

namespace App\Events;

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Shared shape for the four proposal/review broadcasts, whose payload is fixed
 * in docs/design/API.md §06.
 *
 * **The payload is deliberately thin, and that is a security property.** A
 * private channel is authorized once, at subscribe time; every field below then
 * reaches every subscriber on that channel for as long as they stay connected.
 * Broadcasting a ProposalResource instead would ship `can` (one user's
 * permissions), `my_review`, review counts and full author details to an entire
 * role. Subclasses choose channels and a type; none of them may widen this.
 * tests/Feature/Realtime/EventPayloadTest.php pins the exact key set.
 *
 * The client patches its store from this, or refetches the one record it needs.
 * Where a screen wants a field that is not here — an updated average rating,
 * say — the answer is a refetch, not another key.
 *
 * ShouldDispatchAfterCommit, not a bare dispatch at the end of each Action: the
 * writes live inside DB::transaction(), and an event fired inside one is a
 * promise the database has not yet made. A rolled-back transaction would
 * otherwise broadcast a proposal that never existed, and the queue worker —
 * a separate process — can outrun an uncommitted write and re-read stale rows.
 * Declaring it here makes that true for every call site instead of asking each
 * one to remember.
 */
abstract class ProposalBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $occurredAt;

    public function __construct(
        public readonly Proposal $proposal,
        public readonly User $actor,
    ) {
        // Stamped when the thing happened, not when the worker gets to it.
        // These are queued, so those two moments are genuinely different.
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
        return [
            'type' => $this->type(),
            'proposal' => [
                'id' => $this->proposal->id,
                'ref' => $this->proposal->ref(),
                'title' => $this->proposal->title,
                'status' => $this->proposal->status->value,
            ],
            'actor' => [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'initials' => $this->actor->initials(),
            ],
            'occurred_at' => $this->occurredAt,
        ];
    }
}
