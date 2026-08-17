<?php

namespace App\Actions\Reviews;

use App\Data\ReviewData;
use App\Events\ReviewCreated;
use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use App\Services\ActivityNotifier;
use Illuminate\Support\Facades\DB;

final class SubmitReview
{
    public function __construct(private ActivityNotifier $notifier) {}

    /**
     * One review per reviewer per proposal: a second call updates the first. The
     * unique index is the real guarantee; updateOrCreate keeps the API friendly.
     */
    public function handle(User $reviewer, Proposal $proposal, ReviewData $data): Review
    {
        return DB::transaction(function () use ($reviewer, $proposal, $data): Review {
            $review = Review::updateOrCreate(
                ['proposal_id' => $proposal->id, 'user_id' => $reviewer->id],
                ['rating' => $data->rating, 'comment' => $data->comment],
            );

            // Only a first review broadcasts. Revisions come through here too, and
            // API.md §06 has no `review.updated` — firing `review.created` for an edit
            // would ping the author over a fixed typo.
            if ($review->wasRecentlyCreated) {
                ReviewCreated::dispatch($proposal, $reviewer);
                $this->notifier->reviewCreated($proposal, $reviewer);
            }

            return $review->load('reviewer.roles');
        });
    }
}
