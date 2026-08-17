<?php

namespace App\Http\Controllers\Api;

use App\Actions\Proposals\DeleteProposal;
use App\Actions\Proposals\RemoveAttachment;
use App\Actions\Proposals\SubmitProposal;
use App\Actions\Proposals\UpdateProposal;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexProposalRequest;
use App\Http\Requests\StoreProposalRequest;
use App\Http\Requests\UpdateProposalRequest;
use App\Http\Resources\ProposalResource;
use App\Models\Proposal;
use App\Repositories\Contracts\ProposalRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProposalController extends Controller
{
    public function store(StoreProposalRequest $request, SubmitProposal $action): JsonResponse
    {
        $this->authorize('create', Proposal::class);

        $proposal = $action->handle($request->user(), $request->toData());

        // Flat, not JsonResource's default `data` wrapper — ->response() would wrap it.
        return response()->json(new ProposalResource($proposal), 201);
    }

    public function index(IndexProposalRequest $request, ProposalRepository $repo): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Proposal::class);

        $viewer = $request->user();
        $page = $repo->paginate($request->toData(), $viewer);

        return ProposalResource::collection($page)->additional([
            // Deliberately unfiltered by search/tags/status, and not re-derived here
            // — see EloquentProposalRepository::counts().
            'counts' => $repo->counts($viewer),
        ]);
    }

    public function show(int $proposal, ProposalRepository $repo, Request $request): JsonResponse
    {
        // Scopes then findOrFail()s, so another speaker's proposal 404s rather than
        // 403s and the id's existence stays undisclosed.
        $model = $repo->findForViewer($proposal, $request->user());

        $this->authorize('view', $model);

        // Flat, not a {data, max_rating} envelope — ->additional() would wrap it in
        // "data", unlike every other single-resource response.
        return response()->json([
            ...(new ProposalResource($model))->toArray($request),
            'max_rating' => config('review.max_rating'),
            'rating_distribution' => $repo->ratingDistribution($model),
        ]);
    }

    public function update(UpdateProposalRequest $request, Proposal $proposal, UpdateProposal $action): JsonResponse
    {
        // The 404-for-visibility guard lives in UpdateProposalRequest::authorize(),
        // which runs before validation. This is the separate "may they edit it" check.
        $this->authorize('update', $proposal);

        $updated = $action->handle($request->user(), $proposal, $request->toData());

        return response()->json(new ProposalResource($updated));
    }

    public function destroy(Request $request, Proposal $proposal, DeleteProposal $action): Response
    {
        // 404, not 403 — mirrors ProposalController::show's own existence-hiding scope.
        abort_unless($request->user()->can('view', $proposal), 404);
        $this->authorize('delete', $proposal);

        $action->handle($proposal);

        return response()->noContent();
    }

    public function destroyAttachment(Request $request, Proposal $proposal, RemoveAttachment $action): Response
    {
        abort_unless($request->user()->can('view', $proposal), 404);
        // The update gate, not delete: a decided proposal's PDF is as frozen as its
        // text, so an admin cannot strip the PDF from one. That asymmetry is intended.
        $this->authorize('update', $proposal);

        $action->handle($proposal);

        return response()->noContent();
    }
}
