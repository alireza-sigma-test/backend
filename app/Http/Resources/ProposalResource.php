<?php
// app/Http/Resources/ProposalResource.php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Proposal */
class ProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $media = $this->attachment();

        return [
            'id' => $this->id,
            'ref' => $this->ref(),
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'author' => [
                'id' => $this->author->id,
                'name' => $this->author->name,
                'initials' => $this->author->initials(),
            ],
            'attachment' => $media === null ? null : [
                'filename' => $media->file_name,
                'size_bytes' => $media->size,
                'mime' => $media->mime_type,
                // Temporary signed URL — the disk is private.
                'url' => $media->getTemporaryUrl(now()->addMinutes(30)),
            ],
            'average_rating' => $this->reviews_avg_rating === null
                ? null
                : round((float) $this->reviews_avg_rating, 1),
            'reviews_count' => (int) ($this->reviews_count ?? 0),
            'my_review' => $this->when(
                $viewer !== null && $this->relationLoaded('myReview'),
                fn () => $this->myReview ? new ReviewResource($this->myReview) : null
            ),
            // Generated from the policy so the client never infers permission
            // from a role string. Rendering only — every mutating route still
            // calls Gate::authorize.
            'can' => [
                'edit' => $viewer?->can('update', $this->resource) ?? false,
                'review' => $viewer?->can('review', $this->resource) ?? false,
                'change_status' => $viewer?->can('changeStatus', $this->resource) ?? false,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
