<?php

// app/Http/Controllers/Api/ProposalController.php

namespace App\Http\Controllers\Api;

use App\Actions\Proposals\DeleteProposal;
use App\Actions\Proposals\SubmitProposal;
use App\Actions\Proposals\UpdateProposal;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexProposalRequest;
use App\Http\Requests\StoreProposalRequest;
use App\Http\Requests\UpdateProposalRequest;
use App\Http\Resources\ProposalResource;
use App\Models\Proposal;
use App\Repositories\Contracts\ProposalRepository;
use App\Services\AttachmentStore;
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
            'rating_distribution' => $repo->ratingDistribution($model),
        ]);
    }

    public function update(UpdateProposalRequest $request, Proposal $proposal, UpdateProposal $action): JsonResponse
    {
        // 404, not 403 — mirrors ProposalController::show's own existence-hiding scope.
        abort_unless($request->user()->can('view', $proposal), 404);
        $this->authorize('update', $proposal);

        $updated = $action->handle($proposal, $request->toData());

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

    public function destroyAttachment(Request $request, Proposal $proposal, AttachmentStore $attachments): Response
    {
        abort_unless($request->user()->can('view', $proposal), 404);
        // Editing the attachment is editing the proposal — same gate, so a
        // decided proposal's PDF is as frozen as its text. An admin who can
        // delete the whole proposal cannot strip just the PDF from a decided
        // one; that asymmetry is intentional (docs/API.md files this under
        // "03 · Submit a proposal", not the admin decision endpoints).
        $this->authorize('update', $proposal);

        $attachments->remove($proposal);

        return response()->noContent();
    }
}
