<?php

// app/Actions/Reviews/UpdateReview.php

namespace App\Actions\Reviews;

use App\Data\UpdateReviewData;
use App\Models\Proposal;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

final class UpdateReview
{
    /**
     * @return array{review: Review, proposal: Proposal}
     *
     * The controller needs the proposal's fresh review aggregates for the
     * response envelope; hydrating them here — rather than leaving the
     * controller to re-query — mirrors ChangeProposalStatus's
     * ['proposal' => …, 'change' => …] shape and keeps the query out of
     * App\Http\Controllers, same as every other write on this branch.
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
