<?php
// app/Http/Controllers/Api/ProposalController.php
namespace App\Http\Controllers\Api;

use App\Actions\Proposals\SubmitProposal;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProposalRequest;
use App\Http\Resources\ProposalResource;
use App\Models\Proposal;
use Illuminate\Http\JsonResponse;

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
}
