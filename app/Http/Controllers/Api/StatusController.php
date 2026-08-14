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
        $this->authorize('changeStatus', $proposal);

        $admin = $request->user();
        $updated = $action->handle($admin, $proposal, $request->toData());

        return response()->json([
            'proposal' => new ProposalResource($updated->loadCount('reviews')->loadAvg('reviews', 'rating')),
            'changed_by' => new UserResource($admin->load('roles')),
            'changed_at' => now()->toIso8601String(),
        ]);
    }
}
