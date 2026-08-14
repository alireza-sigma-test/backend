<?php

// app/Http/Controllers/Api/ReviewController.php

namespace App\Http\Controllers\Api;

use App\Actions\Reviews\SubmitReview;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Proposal;
use Illuminate\Http\JsonResponse;

class ReviewController extends Controller
{
    public function store(StoreReviewRequest $request, Proposal $proposal, SubmitReview $action): JsonResponse
    {
        // 404, not 403 — mirrors ProposalController::show's own existence-hiding scope.
        abort_unless($request->user()->can('view', $proposal), 404);
        $this->authorize('review', $proposal);

        $review = $action->handle($request->user(), $proposal, $request->toData());

        $proposal->loadCount('reviews')->loadAvg('reviews', 'rating');

        return response()->json([
            'review' => new ReviewResource($review),
            'average_rating' => $proposal->reviews_avg_rating === null
                ? null
                : round((float) $proposal->reviews_avg_rating, 1),
            'reviews_count' => (int) $proposal->reviews_count,
        ], 201);
    }
}
