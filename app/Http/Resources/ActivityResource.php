<?php

// app/Http/Resources/ActivityResource.php

namespace App\Http\Resources;

use App\Support\ActivityPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One feed row, in the same shape app/Events broadcasts live — see
 * ActivityPayload, which both sides call. `id` is added on top: the payload
 * itself has none, because a broadcast event is not addressable, while a feed
 * row needs a stable key for the client's list.
 */
class ActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // "review.created:7" — the source table's own id, namespaced by
            // type. Unique across the three arms of the union, which a bare
            // row number would not be.
            'id' => $this->resource->id,
            ...ActivityPayload::make(
                $this->resource->type,
                $this->resource->proposal,
                $this->resource->actor,
                $this->resource->occurred_at,
            ),
        ];
    }
}
