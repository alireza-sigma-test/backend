<?php

namespace App\Http\Controllers\Api;

use App\Actions\Proposals\ChangeProposalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeStatusRequest;
use App\Http\Resources\ProposalResource;
use App\Http\Resources\UserResource;
use App\Models\Proposal;
use Illuminate\Http\JsonResponse;

class StatusController extends Controller
{
    public function update(ChangeStatusRequest $request, Proposal $proposal, ChangeProposalStatus $action): JsonResponse
    {
        // The 404-for-visibility guard lives in ChangeStatusRequest::authorize(), which
        // runs before validation. This is the separate admin-only check.
        $this->authorize('changeStatus', $proposal);

        $admin = $request->user();
        ['proposal' => $updated, 'change' => $change] = $action->handle($admin, $proposal, $request->toData());

        return response()->json([
            'proposal' => new ProposalResource($updated->loadCount('reviews')->loadAvg('reviews', 'rating')),
            'changed_by' => new UserResource($admin->load('roles')),
            // The audit row's own timestamp, not a second now() — /history reads
            // changed_at from this column. Null only for a no-op, which records nothing.
            'changed_at' => $change?->created_at?->toIso8601String(),
        ]);
    }
}
