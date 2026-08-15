<?php

// app/Actions/Reviews/UpdateReview.php

namespace App\Actions\Reviews;

use App\Data\UpdateReviewData;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

final class UpdateReview
{
    public function handle(Review $review, UpdateReviewData $data): Review
    {
        return DB::transaction(function () use ($review, $data): Review {
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

            return $review->fresh('reviewer.roles');
        });
    }
}
