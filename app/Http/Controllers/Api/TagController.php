<?php
// app/Http/Controllers/Api/TagController.php
namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TagController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // Same speaker predicate as EloquentProposalRepository::scope(): a
        // speaker's count must reflect only proposals they can see, or filtering
        // by a tag used solely by other speakers returns zero rows.
        $viewer = $request->user();

        return TagResource::collection(
            Tag::withCount(['proposals' => fn ($query) => $viewer->hasRole(UserRole::Speaker->value)
                ? $query->where('user_id', $viewer->id)
                : $query,
            ])->orderBy('name')->get()
        );
    }
}
