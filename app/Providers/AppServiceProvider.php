<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(6)->by($r->ip()));
        RateLimiter::for('register', fn (Request $r) => Limit::perMinute(6)->by($r->ip()));
        RateLimiter::for('verify-email', fn (Request $r) => Limit::perMinute(6)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('resend-code', fn (Request $r) => Limit::perMinutes(10, 3)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('accept-invite', fn (Request $r) => Limit::perMinute(6)->by($r->ip()));

        // The whole admin group sat with no limiter at all — every other
        // sensitive route in this app has a named one. Keyed by user id
        // (every route inside is already behind auth:sanctum, so it is
        // always present): defence in depth alongside the `admin` gate
        // itself, not a substitute for it — the gate is what stops a
        // non-admin from reaching the enumeration surface; this just caps
        // how fast even an admin account can be made to probe it.
        RateLimiter::for('admin', fn (Request $r) => Limit::perMinute(30)->by($r->user()?->id ?: $r->ip()));

        // Scramble ships a `local`-only gate. This is a portfolio API whose
        // reviewers run it in Docker with APP_ENV=local, so the default would
        // hide the docs from exactly the audience they exist for. Opening them
        // is a deliberate choice: every documented route still enforces
        // Sanctum and its policy, so the document is a map, not a key.
        Gate::define('viewApiDocs', fn (?User $user) => true);

        // The bearer scheme itself, and which routes require it, are configured
        // via `security_strategy` in config/scramble.php — not here. It inspects
        // each route's actual `auth:sanctum` middleware, so `/api/register` and
        // `/api/login` are correctly documented as unauthenticated instead of
        // every route inheriting a single document-wide requirement.
    }
}
