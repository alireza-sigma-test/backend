<?php
// app/Actions/Reviews/SubmitReview.php
namespace App\Actions\Reviews;

use App\Data\ReviewData;
use App\Models\{Proposal, Review, User};
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

            return $review->load('reviewer.roles');
        });
    }
}
