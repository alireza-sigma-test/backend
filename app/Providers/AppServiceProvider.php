<?php

namespace App\Providers;

use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
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

        // Scramble ships a `local`-only gate. This is a portfolio API whose
        // reviewers run it in Docker with APP_ENV=local, so the default would
        // hide the docs from exactly the audience they exist for. Opening them
        // is a deliberate choice: every documented route still enforces
        // Sanctum and its policy, so the document is a map, not a key.
        Gate::define('viewApiDocs', fn (?User $user) => true);

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $document): void {
                $document->secure(SecurityScheme::http('bearer'));
            });
    }
}
