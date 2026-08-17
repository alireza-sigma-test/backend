<?php

namespace App\Support;

use App\Models\Proposal;
use App\Models\User;

/**
 * The one definition of the event payload from docs/design/API.md §06, shared by
 * the websocket broadcasts and the HTTP activity feed so a client can render both
 * with one component.
 *
 * The narrowness is a security property: a role channel is authorized once at
 * subscribe time, so anything added here reaches every subscriber unchecked.
 * EventPayloadTest pins the key set.
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
