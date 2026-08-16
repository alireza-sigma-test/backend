<?php

// app/Http/Controllers/Api/Admin/UserController.php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\ChangeUserRole;
use App\Actions\Admin\CreateUserByAdmin;
use App\Actions\Admin\ReinviteUser;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminCreateUserRequest;
use App\Http\Requests\ChangeUserRoleRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Repositories\Contracts\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function index(UserRepository $repo): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        return UserResource::collection($repo->paginate());
    }

    public function store(AdminCreateUserRequest $request, CreateUserByAdmin $action): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = $action->handle($request->toData());

        // Flat, not JsonResource's default `data` wrapper — same convention as
        // ProposalController::store().
        return response()->json(new UserResource($user), 201);
    }

    public function updateRole(ChangeUserRoleRequest $request, User $user, ChangeUserRole $action): JsonResponse
    {
        $this->authorize('updateRole', $user);

        $updated = $action->handle($user, UserRole::from($request->string('role')->value()));

        return response()->json(new UserResource($updated));
    }

    public function reinvite(User $user, ReinviteUser $action): JsonResponse
    {
        $this->authorize('reinvite', $user);

        $updated = $action->handle($user);

        return response()->json(new UserResource($updated));
    }
}
