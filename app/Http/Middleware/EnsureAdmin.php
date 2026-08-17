<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prepended ahead of SubstituteBindings in the middleware priority list (see
 * bootstrap/app.php), so a non-admin is refused before route-model binding and
 * before any Form Request validates. Without that ordering,
 * AdminCreateUserRequest's `unique:users,email` answers first and turns
 * POST /api/admin/users into an email-existence oracle. The authorize() calls in
 * Admin\UserController remain as defence in depth.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null || ! $request->user()->hasRole(UserRole::Admin->value)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        return $next($request);
    }
}
