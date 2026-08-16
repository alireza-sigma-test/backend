<?php

// app/Http/Controllers/Api/StatsController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Proposal;
use App\Repositories\Contracts\ProposalRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function __invoke(Request $request, ProposalRepository $repo): JsonResponse
    {
        // Stats aggregate across every author, so a speaker seeing it would
        // learn the size and disposition of the whole queue — admin only.
        // Routed through ProposalPolicy::viewStats, like every other
        // per-record and per-role decision in the app (README.md: "Policies
        // are the single source of per-record authorization").
        $this->authorize('viewStats', Proposal::class);

        $counts = $repo->counts($request->user());

        return response()->json([
            // `counts()` names this key `all`; the contract names it `total`.
            'total' => $counts['all'],
            'pending' => $counts['pending'],
            'approved' => $counts['approved'],
            'rejected' => $counts['rejected'],
            'ready_to_decide' => $repo->readyToDecide(),
        ]);
    }
}
