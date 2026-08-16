<?php

// app/Support/ActivityPayload.php

namespace App\Support;

use App\Models\Proposal;
use App\Models\User;

/**
 * The one definition of the event payload fixed in docs/design/API.md §06.
 *
 * Two very different code paths produce it: app/Events/* broadcasts it over a
 * websocket, and the activity feed returns it over HTTP. They exist so a client
 * can render a live push and a fetched row with the same component — which only
 * holds while the two shapes are identical, and they only stay identical if
 * there is one place to change.
 *
 * **The narrowness is the security property.** A private channel is authorized
 * once at subscribe time, so every field here reaches every subscriber on a
 * role channel with nothing re-checking them. That is why there is no
 * description, no tags, no review counts, no `can` block and no author details:
 * broadcasting a ProposalResource would ship one user's permissions to a whole
 * role. tests/Feature/Realtime/EventPayloadTest.php pins the exact key set, so
 * widening this fails loudly rather than shipping quietly.
 */
final class ActivityPayload
{
    /** @return array<string, mixed> */
    public static function make(string $type, Proposal $proposal, User $actor, string $occurredAt): array
    {
        return [
            'type' => $type,
            'proposal' => [
                'id' => $proposal->id,
                'ref' => $proposal->ref(),
                'title' => $proposal->title,
                'status' => $proposal->status->value,
            ],
            'actor' => [
                'id' => $actor->id,
                'name' => $actor->name,
                'initials' => $actor->initials(),
            ],
            'occurred_at' => $occurredAt,
        ];
    }
}
