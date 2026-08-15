<?php

// app/Http/Controllers/Api/HistoryController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StatusChangeResource;
use App\Models\Proposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HistoryController extends Controller
{
    public function __invoke(Request $request, Proposal $proposal): AnonymousResourceCollection
    {
        // Existence first, permission second — a speaker who cannot see this
        // proposal must get 404, never a 403 that confirms the id is real.
        abort_unless($request->user()->can('view', $proposal), 404);
        $this->authorize('viewHistory', $proposal);

        return StatusChangeResource::collection(
            $proposal->statusChanges()->with('changedBy.roles')->latest()->get()
        );
    }
}
