<?php

// app/Actions/Reviews/SubmitReview.php

namespace App\Actions\Reviews;

use App\Data\ReviewData;
use App\Events\ReviewCreated;
use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SubmitReview
{
    /**
     * One review per reviewer per proposal: a second call updates the first.
     * The database unique index is the real guarantee; updateOrCreate keeps
     * the API contract friendly.
     */
    public function handle(User $reviewer, Proposal $proposal, ReviewData $data): Review
    {
        return DB::transaction(function () use ($reviewer, $proposal, $data): Review {
            $review = Review::updateOrCreate(
                ['proposal_id' => $proposal->id, 'user_id' => $reviewer->id],
                ['rating' => $data->rating, 'comment' => $data->comment],
            );

            // Only a first review broadcasts. This endpoint is updateOrCreate,
            // so a reviewer revising their own comment comes through here too —
            // and API.md §06's vocabulary has no `review.updated`. Firing
            // `review.created` for an edit would report something that did not
            // happen, and would ping the author every time a reviewer fixed a
            // typo. Editing a review still changes the average rating, which
            // the detail screen reads on its own next fetch.
            if ($review->wasRecentlyCreated) {
                ReviewCreated::dispatch($proposal, $reviewer);
            }

            return $review->load('reviewer.roles');
        });
    }
}
