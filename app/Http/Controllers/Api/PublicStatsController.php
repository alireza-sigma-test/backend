<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Repositories\Contracts\ProposalRepository;
use App\Repositories\Contracts\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PublicStatsController extends Controller
{
    /**
     * Two coarse integers for the signed-out panel. GET /stats stays admin-only — it
     * exposes the decision pipeline. `reviewers` counts role holders, not distinct
     * review authors, so it cannot leak which proposals have been reviewed.
     */
    public function __invoke(ProposalRepository $proposals, UserRepository $users): JsonResponse
    {
        $stats = Cache::remember('public-stats', now()->addMinutes(5), fn () => [
            'proposals_this_year' => $proposals->countCreatedInYear(now()->year),
            'reviewers' => $users->countWithRole(UserRole::Reviewer),
        ]);

        return response()->json($stats);
    }
}
