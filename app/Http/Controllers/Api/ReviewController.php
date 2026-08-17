<?php

namespace App\Http\Controllers\Api;

use App\Actions\Reviews\DeleteReview;
use App\Actions\Reviews\SubmitReview;
use App\Actions\Reviews\UpdateReview;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Requests\UpdateReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Proposal;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ReviewController extends Controller
{
    public function store(StoreReviewRequest $request, Proposal $proposal, SubmitReview $action): JsonResponse
    {
        // The 404-for-visibility guard lives in StoreReviewRequest::authorize(), which
        // runs before validation. This is the separate "may they review it" check.
        $this->authorize('review', $proposal);

        $review = $action->handle($request->user(), $proposal, $request->toData());

        $proposal->loadCount('reviews')->loadAvg('reviews', 'rating');

        return response()->json($this->envelope($review, $proposal), 201);
    }

    public function update(UpdateReviewRequest $request, Review $review, UpdateReview $action): JsonResponse
    {
        $this->authorize('update', $review);

        // Aggregates come back from the Action, not a controller-level query.
        ['review' => $updated, 'proposal' => $proposal] = $action->handle($review, $request->toData());

        return response()->json($this->envelope($updated, $proposal));
    }

    public function destroy(Review $review, DeleteReview $action): Response
    {
        $this->authorize('delete', $review);

        $action->handle($review);

        return response()->noContent();
    }

    /** The {review, average_rating, reviews_count} envelope shared by store() and update(). */
    private function envelope(Review $review, Proposal $proposal): array
    {
        return [
            'review' => new ReviewResource($review),
            'average_rating' => $proposal->reviews_avg_rating === null
                ? null
                : round((float) $proposal->reviews_avg_rating, 1),
            'reviews_count' => (int) $proposal->reviews_count,
        ];
    }
}
