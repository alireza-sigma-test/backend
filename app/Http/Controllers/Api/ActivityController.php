<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexActivityRequest;
use App\Http\Resources\ActivityResource;
use App\Repositories\Contracts\ActivityRepository;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * No policy and no authorize() call: there is no id to name and nothing to authorize
 * against, so the scoping *is* the authorization. It lives in
 * EloquentActivityRepository, off the same visibleQuery() GET /api/proposals uses.
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
