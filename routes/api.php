<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProposalController;
use App\Http\Controllers\Api\PublicStatsController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\TagController;
use Illuminate\Support\Facades\Route;

// Named limiters, not inline `throttle:6,1`: an unnamed throttle is keyed by
// domain+IP, so routes sharing the literal string share one bucket — failed
// registrations would lock a user out of logging in.
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/invites/accept', [InviteController::class, 'accept'])->middleware('throttle:accept-invite');

// The only unauthenticated read surface, feeding the signed-out login screen.
Route::get('/public-stats', PublicStatsController::class)->middleware('throttle:public-stats');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Must stay outside the `verified` group below: the caller is precisely a
    // signed-in but unverified user, so gating these closes the only route out of
    // unverified.
    Route::post('/email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:verify-email');
    Route::post('/email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:resend-code');

    Route::get('/proposals', [ProposalController::class, 'index']);
    Route::get('/proposals/{proposal}', [ProposalController::class, 'show'])->whereNumber('proposal');
    Route::get('/proposals/{proposal}/history', HistoryController::class)->whereNumber('proposal');

    // One decision rather than eight chances to forget it.
    Route::middleware('verified')->group(function () {
        Route::post('/proposals', [ProposalController::class, 'store']);
        Route::patch('/proposals/{proposal}', [ProposalController::class, 'update'])->whereNumber('proposal');
        Route::delete('/proposals/{proposal}', [ProposalController::class, 'destroy'])->whereNumber('proposal');
        Route::delete('/proposals/{proposal}/attachment', [ProposalController::class, 'destroyAttachment'])->whereNumber('proposal');
        Route::post('/proposals/{proposal}/reviews', [ReviewController::class, 'store'])->whereNumber('proposal');
        Route::patch('/reviews/{review}', [ReviewController::class, 'update'])->whereNumber('review');
        Route::delete('/reviews/{review}', [ReviewController::class, 'destroy'])->whereNumber('review');
        Route::patch('/proposals/{proposal}/status', [StatusController::class, 'update'])->whereNumber('proposal');
    });

    // Outside `verified`: these touch only the caller's own read-state, and gating
    // them would leave an unverified reviewer unable to clear their badge.
    Route::get('/activity', ActivityController::class);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    // After read-all so the literal segment wins — `{notification}` would otherwise
    // swallow "read-all".
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);

    Route::get('/tags', [TagController::class, 'index']);
    Route::get('/stats', StatsController::class);

    // `admin` refuses non-admins before route-model binding or Form Request
    // validation (see EnsureAdmin), which is what closes the enumeration oracles on
    // these routes. `verified` nests inside so index stays open, the writes do not.
    Route::prefix('admin')->middleware(['admin', 'throttle:admin'])->group(function () {
        Route::get('/users', [Admin\UserController::class, 'index']);

        Route::middleware('verified')->group(function () {
            Route::post('/users', [Admin\UserController::class, 'store']);
            Route::patch('/users/{user}/role', [Admin\UserController::class, 'updateRole'])->whereNumber('user');
            Route::post('/users/{user}/reinvite', [Admin\UserController::class, 'reinvite'])->whereNumber('user');
        });
    });
});
