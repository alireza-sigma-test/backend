<?php

// app/Http/Resources/StatusChangeResource.php

namespace App\Http\Resources;

use App\Models\ProposalStatusChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProposalStatusChange */
class StatusChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from' => $this->from->value,
            'to' => $this->to->value,
            'note' => $this->note,
            'changed_by' => new UserResource($this->whenLoaded('changedBy')),
            // The model sets UPDATED_AT = null; created_at IS the decision time.
            'changed_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
