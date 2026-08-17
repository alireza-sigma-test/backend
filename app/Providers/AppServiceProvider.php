<?php

namespace App\Providers;

use App\Models\User;
use App\Services\ClaudeProposalSummarizer;
use App\Services\Contracts\ProposalSummarizer;
use App\Services\NullProposalSummarizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // With no API key the app gets a summarizer that reports itself
        // unavailable, not one that fails — so nothing downstream needs a key check.
        $this->app->bind(
            ProposalSummarizer::class,
            fn () => filled(config('ai.providers.anthropic.key'))
                ? new ClaudeProposalSummarizer
                : new NullProposalSummarizer,
        );
    }

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(6)->by($r->ip()));
        RateLimiter::for('register', fn (Request $r) => Limit::perMinute(6)->by($r->ip()));
        RateLimiter::for('verify-email', fn (Request $r) => Limit::perMinute(6)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('resend-code', fn (Request $r) => Limit::perMinutes(10, 3)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('accept-invite', fn (Request $r) => Limit::perMinute(6)->by($r->ip()));

        // The only unauthenticated read surface, so keyed by IP — there is no user.
        RateLimiter::for('public-stats', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));

        // Defence in depth alongside the `admin` gate: caps how fast even an
        // admin account can be made to probe the enumeration surface.
        RateLimiter::for('admin', fn (Request $r) => Limit::perMinute(30)->by($r->user()?->id ?: $r->ip()));

        // Deliberately open, overriding Scramble's `local`-only default: reviewers
        // run this in Docker with APP_ENV=local. Documented routes still enforce
        // Sanctum and their policies.
        Gate::define('viewApiDocs', fn (?User $user) => true);

        // The bearer scheme and which routes require it come from
        // `security_strategy` in config/scramble.php, not from here.
    }
}
