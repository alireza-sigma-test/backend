<?php

// app/Http/Controllers/Api/ProposalController.php

namespace App\Http\Controllers\Api;

use App\Actions\Proposals\SubmitProposal;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexProposalRequest;
use App\Http\Requests\StoreProposalRequest;
use App\Http\Resources\ProposalResource;
use App\Models\Proposal;
use App\Repositories\Contracts\ProposalRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProposalController extends Controller
{
    public function store(StoreProposalRequest $request, SubmitProposal $action): JsonResponse
    {
        $this->authorize('create', Proposal::class);

        $proposal = $action->handle($request->user(), $request->toData());

        // Flat, not JsonResource's default `data` wrapper — same convention as
        // AuthController::me(). ->response() would wrap this in {"data": {...}}
        // since JsonResource::$wrap defaults to 'data'.
        return response()->json(new ProposalResource($proposal), 201);
    }

    public function index(IndexProposalRequest $request, ProposalRepository $repo): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Proposal::class);

        $viewer = $request->user();
        $page = $repo->paginate($request->toData(), $viewer);

        return ProposalResource::collection($page)->additional([
            // Deliberately unfiltered by search/tags/status — see
            // EloquentProposalRepository::counts(). The controller must not
            // re-derive or re-filter this itself.
            'counts' => $repo->counts($viewer),
        ]);
    }

    public function show(int $proposal, ProposalRepository $repo, Request $request): JsonResponse
    {
        // findForViewer scopes to the viewer, then findOrFail()s — a speaker
        // requesting another speaker's proposal 404s, never 403, so the id's
        // existence is not disclosed.
        $model = $repo->findForViewer($proposal, $request->user());

        $this->authorize('view', $model);

        // Flat, not a {data, max_rating} envelope — ->additional() on a returned
        // JsonResource wraps it in "data", unlike every other single-resource
        // response here and in docs/API.md.
        return response()->json([
            ...(new ProposalResource($model))->toArray($request),
            'max_rating' => config('review.max_rating'),
        ]);
    }
}
