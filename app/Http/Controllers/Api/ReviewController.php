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
        $this->authorize('review', $proposal);

        $review = $action->handle($request->user(), $proposal, $request->toData());

        $proposal->loadCount('reviews')->loadAvg('reviews', 'rating');

        return response()->json([
            'review' => new ReviewResource($review),
            'average_rating' => $proposal->reviews_avg_rating === null
                ? null
                : round((float) $proposal->reviews_avg_rating, 1),
            'reviews_count' => (int) $proposal->reviews_count,
            // Without this flag, json_encode drops the trailing zero from a
            // whole-number float (3.0 -> 3), and a client parsing the response
            // sees an int where the contract promises a one-decimal average.
        ], 201, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
