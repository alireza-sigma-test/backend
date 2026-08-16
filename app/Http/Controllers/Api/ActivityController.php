<?php

// app/Http/Controllers/Api/ActivityController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexActivityRequest;
use App\Http\Resources\ActivityResource;
use App\Repositories\Contracts\ActivityRepository;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The other half of the pair with NotificationController: notifications are
 * addressed to you, activity is everything you may see.
 *
 * No authorize() call and no policy, deliberately. There is no id to name here
 * and nothing to authorize *against* — the whole endpoint is one scoped read,
 * and the scoping is the authorization. It lives in
 * EloquentActivityRepository, which builds every arm of its union off
 * ProposalRepository::visibleQuery(), the same rule GET /api/proposals uses.
 */
class ActivityController extends Controller
{
    public function __invoke(IndexActivityRequest $request, ActivityRepository $repo): AnonymousResourceCollection
    {
        return ActivityResource::collection(
            $repo->paginate($request->user(), $request->perPage()),
        );
    }
}
