<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        // Not Laravel's EnsureEmailIsVerified: that one redirects to a
        // `verification.notice` route this application does not define, which
        // is how a missing named route becomes a 500 with a stack trace.
        if ($request->user() === null || ! $request->user()->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Confirm your email address before making changes.',
                // A stable marker so the client can prompt for the code instead
                // of rendering a generic permission error.
                'code' => 'email_unverified',
            ], 403);
        }

        return $next($request);
    }
}
