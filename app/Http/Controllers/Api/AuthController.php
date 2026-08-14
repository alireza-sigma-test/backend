<?php
// app/Http/Controllers/Api/AuthController.php
namespace App\Http\Controllers\Api;

use App\Actions\Auth\{LoginUser, LogoutUser, RegisterUser};
use App\Http\Controllers\Controller;
use App\Http\Requests\{LoginRequest, RegisterRequest};
use App\Http\Resources\UserResource;
use Illuminate\Http\{JsonResponse, Request, Response};

class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterUser $action): JsonResponse
    {
        ['token' => $token, 'user' => $user] = $action->handle($request->toData());

        return response()->json(['token' => $token, 'user' => new UserResource($user)], 201);
    }

    public function login(LoginRequest $request, LoginUser $action): JsonResponse
    {
        ['token' => $token, 'user' => $user] = $action->handle($request->toData());

        return response()->json(['token' => $token, 'user' => new UserResource($user)]);
    }

    public function logout(Request $request, LogoutUser $action): Response
    {
        $action->handle($request->user());

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roles'));
    }
}
