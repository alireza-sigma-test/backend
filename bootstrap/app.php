<?php

use App\Exceptions\LastAdminException;
use App\Exceptions\UserNotReinvitableException;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // No web login route exists; returning null keeps AuthenticationException
        // redirect-free so the handler renders a clean 401 for every client.
        $middleware->redirectGuestsTo(fn () => null);

        // Deliberately shadows Laravel's own `verified` alias: the stock
        // Illuminate\Auth\Middleware\EnsureEmailIsVerified redirects to a
        // `verification.notice` route this application does not define,
        // which is the same shape as the earlier defect where a guest
        // redirect to a missing named route produced a 500 with a stack
        // trace. Ours returns a stable JSON 403 with a machine-readable
        // `code` instead.
        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
            'admin' => EnsureAdmin::class,
        ]);

        // Measured, not assumed: Laravel's default $middlewarePriority runs
        // SubstituteBindings (route model binding) before any route
        // middleware that isn't in that list, `verified` included. Left at
        // its default position, an unverified caller hitting a nonexistent
        // proposal/review id 404s from a failed binding before `verified`
        // ever runs, while the same caller hitting a real-but-hidden id
        // reaches `verified` and gets 403 — a status-code difference that
        // discloses which ids exist, reopening the oracle
        // NotFoundEnumerationTest closes elsewhere. Running `verified` before
        // binding (and after auth, which the default priority already
        // guarantees relative to bindings) makes every id — real, hidden or
        // fake — 403 alike for an unverified caller; existence is decided
        // later, only for callers who clear this gate. Pinned by
        // tests/Feature/Auth/UnverifiedWriteGateTest.php.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureEmailIsVerified::class,
        );

        // The same fix, generalised: `admin` guards the whole `admin` route
        // group (see routes/api.php), so it has to run before
        // SubstituteBindings for the same reason `verified` does — otherwise
        // a verified non-admin hitting a real user id reaches route-model
        // binding and then a Form Request's validation (which queries the
        // database, e.g. AdminCreateUserRequest's `unique:users,email`)
        // before the controller's `$this->authorize()` line ever runs. That
        // ordering is exactly what turned POST /api/admin/users into a fifth,
        // unthrottled enumeration oracle and gave the role-change route a
        // 404-vs-403 user-id leak. See EnsureAdmin's docblock.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureAdmin::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApiRequest = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApiRequest);

        // Closes the 404 enumeration oracle at the body, not just the status
        // code. `abort_unless($user->can('view', $proposal), 404)` — used by
        // several controllers to make a real-but-forbidden id indistinguishable
        // from one that doesn't exist — already matched status codes, but the
        // *bodies* still gave it away: abort_unless's bare 404 renders an empty
        // message, while a route-model-binding failure (or an explicit
        // findOrFail()) throws Eloquent's ModelNotFoundException, which
        // Handler::prepareException() converts to this same NotFoundHttpException
        // class *before* any render() callback below ever runs (see
        // vendor/laravel/framework/.../Exceptions/Handler.php: render() calls
        // prepareException() at the top, then renderViaCallbacks() after) — so a
        // single callback typed on NotFoundHttpException catches both origins
        // and can render one identical body for either. Gated on the same
        // API/JSON test as shouldRenderJsonWhen so a browser 404 (e.g. Task 7's
        // /docs/api) is untouched.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return response()->json(['message' => 'Not Found.'], 404);
        });

        // Same status UserPolicy::updateRole already returns for
        // self-demotion — this is the same rule, just enforced at the level
        // of the whole admin set instead of a single row. See
        // ChangeUserRole and LastAdminException for why.
        $exceptions->render(fn (LastAdminException $e) => response()->json([
            'message' => $e->getMessage(),
            'code' => 'last_admin',
        ], 403));

        // Thrown by ReinviteUser when the target has already claimed their
        // account (a real password only they know) or was never invited
        // through this flow at all (a self-registered user, whose real
        // password reinvite would otherwise silently overwrite). 422, not
        // 403: the caller is a genuine admin correctly authorized for the
        // route — the request just doesn't apply to this user's state.
        $exceptions->render(fn (UserNotReinvitableException $e) => response()->json([
            'message' => $e->getMessage(),
            'code' => 'not_reinvitable',
        ], 422));
    })->create();
