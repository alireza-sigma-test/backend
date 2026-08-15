<?php

// app/Actions/Reviews/DeleteReview.php

namespace App\Actions\Reviews;

use App\Models\Review;

final class DeleteReview
{
    public function handle(Review $review): void
    {
        // No transaction: one row, one statement. The average is derived at
        // read time by loadAvg, so nothing needs recomputing here.
        $review->delete();
    }
}
