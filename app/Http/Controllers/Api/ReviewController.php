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
        // Proposal is bound implicitly and unscoped, unlike ProposalController::show's
        // findForViewer(). Without this check a speaker gets 403 for a real id and 404
        // for a fake one, enumerating the id space show() deliberately hides.
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
