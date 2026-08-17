<?php

namespace App\Actions\Reviews;

use App\Data\UpdateReviewData;
use App\Models\Proposal;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

final class UpdateReview
{
    /**
     * Returns the proposal with fresh aggregates so the controller does not re-query,
     * mirroring ChangeProposalStatus's shape.
     *
     * @return array{review: Review, proposal: Proposal}
     */
    public function handle(Review $review, UpdateReviewData $data): array
    {
        return DB::transaction(function () use ($review, $data): array {
            $changes = [];

            if ($data->rating !== null) {
                $changes['rating'] = $data->rating;
            }

            if ($data->commentProvided) {
                $changes['comment'] = $data->comment;
            }

            if ($changes !== []) {
                $review->update($changes);
            }

            $proposal = $review->proposal()->withCount('reviews')->withAvg('reviews', 'rating')->firstOrFail();

            return [
                'review' => $review->fresh('reviewer.roles'),
                'proposal' => $proposal,
            ];
        });
    }
}
