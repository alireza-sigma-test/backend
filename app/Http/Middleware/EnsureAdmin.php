<?php

// app/Http/Middleware/EnsureAdmin.php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The same shape as EnsureEmailIsVerified, generalised to the admin group:
 * a route-level gate, prepended in the middleware priority list ahead of
 * SubstituteBindings (see bootstrap/app.php), so a non-admin is refused
 * before route-model binding runs and — critically — before any Form
 * Request attached to the controller action is resolved and validated.
 *
 * Without this, `AdminCreateUserRequest`'s `unique:users,email` rule queried
 * the database and answered before `UserController::store`'s in-controller
 * `$this->authorize('create', ...)` ever ran, turning `POST /api/admin/users`
 * into a fifth, unthrottled email-existence oracle for any authenticated,
 * verified non-admin. The same ordering gave `PATCH /api/admin/users/{user}/role`
 * a 404-vs-403 user-id oracle for the same reason. Gating the whole group here
 * closes both at once, the same way EnsureEmailIsVerified's prepend closed the
 * equivalent proposal/review oracle.
 *
 * `$this->authorize(...)` calls in Admin\UserController stay in place as
 * defence in depth; this middleware is what makes them run only for callers
 * who could not have leaked anything by getting there.
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
