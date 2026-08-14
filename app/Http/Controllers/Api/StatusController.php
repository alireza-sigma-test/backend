<?php
// app/Http/Controllers/Api/StatusController.php
namespace App\Http\Controllers\Api;

use App\Actions\Proposals\ChangeProposalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeStatusRequest;
use App\Http\Resources\{ProposalResource, UserResource};
use App\Models\Proposal;
use Illuminate\Http\JsonResponse;

class StatusController extends Controller
{
    public function update(ChangeStatusRequest $request, Proposal $proposal, ChangeProposalStatus $action): JsonResponse
    {
        // Proposal is bound implicitly and unscoped, unlike ProposalController::show's
        // findForViewer(). Without this check a speaker gets 403 for a real id and 404
        // for a fake one, enumerating the id space show() deliberately hides.
        abort_unless($request->user()->can('view', $proposal), 404);

        $this->authorize('changeStatus', $proposal);

        $admin = $request->user();
        ['proposal' => $updated, 'change' => $change] = $action->handle($admin, $proposal, $request->toData());

        return response()->json([
            'proposal' => new ProposalResource($updated->loadCount('reviews')->loadAvg('reviews', 'rating')),
            'changed_by' => new UserResource($admin->load('roles')),
            // The audit row's own timestamp, not a second now(). /history will
            // read changed_at from the same column, so both endpoints must mean
            // the same thing. Null only for a no-op, which records nothing.
            'changed_at' => $change?->created_at?->toIso8601String(),
        ]);
    }
}
