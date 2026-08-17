<?php

namespace App\Http\Resources;

use App\Support\ActivityPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One feed row, in the same shape app/Events broadcasts live via ActivityPayload.
 * `id` is added on top: a broadcast event is not addressable, but a list row needs a
 * stable key.
 */
class ActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // "review.created:7" — namespaced by type, so it is unique across the
            // three arms of the union, which a bare row id would not be.
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
