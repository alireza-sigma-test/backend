<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
    })->create();
