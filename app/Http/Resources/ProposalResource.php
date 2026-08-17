<?php

namespace App\Http\Resources;

use App\Models\Proposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Proposal */
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
            // Explicit null rather than an omitted key even when myReview was never
            // eager-loaded, so a typed client can declare `Review | null`.
            'my_review' => $this->when(
                $viewer !== null,
                fn () => ($this->relationLoaded('myReview') && $this->myReview)
                    ? new ReviewResource($this->myReview)
                    : null
            ),
            'reviews' => $this->when(
                $this->relationLoaded('reviews'),
                fn () => $this->reviews->map(function ($review) use ($viewer) {
                    // The owning speaker sees the comment and date, never the score or
                    // the reviewer. Enforced server-side, not by the client.
                    $isOwningSpeaker = $viewer !== null && $viewer->id === $this->user_id;

                    return $isOwningSpeaker
                        ? [
                            'id' => $review->id,
                            'comment' => $review->comment,
                            'created_at' => $review->created_at?->toIso8601String(),
                        ]
                        : (new ReviewResource($review))->toArray(request());
                })->values()
            ),
            // Both keys omitted, not nulled: a null would still tell the author that a
            // summary of their proposal exists, which is what the policy protects.
            ...($viewer?->can('viewSummary', $this->resource)
                ? [
                    'summary' => $this->summary,
                    'summary_status' => $this->summary_status?->value,
                ]
                : []),

            // From the policy, so the client never infers permission from a role
            // string. Rendering only — every mutating route still calls authorize.
            'can' => [
                'edit' => $viewer?->can('update', $this->resource) ?? false,
                'review' => $viewer?->can('review', $this->resource) ?? false,
                'change_status' => $viewer?->can('changeStatus', $this->resource) ?? false,
                'view_summary' => $viewer?->can('viewSummary', $this->resource) ?? false,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
