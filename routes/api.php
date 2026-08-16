<?php

// routes/api.php

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

// Named limiters, not the inline `throttle:6,1` form. Laravel keys an unnamed
// throttle by domain+IP only — never the route path — so two routes sharing the
// literal string share one bucket, and failed registrations would lock a user
// out of logging in.
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/invites/accept', [InviteController::class, 'accept'])->middleware('throttle:accept-invite');

// Deliberately outside the auth:sanctum group below — it is the only
// unauthenticated read surface in the app, feeding the signed-out login
// screen's two marketing counters.
Route::get('/public-stats', PublicStatsController::class)->middleware('throttle:public-stats');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Deliberately here, directly in auth:sanctum — not nested inside any
    // verification gate. The caller is precisely a signed-in but unverified
    // user, so gating these would close the only route out of unverified and
    // make it impossible for any account to ever become one. A later task
    // wraps the mutating routes below in a `verified` group; do not let that
    // restructure sweep these two in.
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

    // Deliberately outside the `verified` group above. These touch nothing
    // but the caller's own read-state and disclose nothing they were not
    // already sent; gating them would leave an unverified reviewer with a
    // badge that only ever counts up and no way to clear it.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    // Registered AFTER read-all so the literal segment wins: notification ids
    // are uuids, and `{notification}` would otherwise swallow "read-all" and
    // 404 it.
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);

    Route::get('/tags', [TagController::class, 'index']);
    Route::get('/stats', StatsController::class);

    // `admin` refuses every non-admin before route-model binding or any Form
    // Request validation runs (see EnsureAdmin and its prepend in
    // bootstrap/app.php) — closing the fifth enumeration oracle on `store`
    // and the id-disclosure leak on `updateRole` at the same time. `verified`
    // still nests inside it, same as the proposal/review group above: index
    // stays open to an unverified admin, the two writes do not.
    Route::prefix('admin')->middleware(['admin', 'throttle:admin'])->group(function () {
        Route::get('/users', [Admin\UserController::class, 'index']);

        Route::middleware('verified')->group(function () {
            Route::post('/users', [Admin\UserController::class, 'store']);
            Route::patch('/users/{user}/role', [Admin\UserController::class, 'updateRole'])->whereNumber('user');
            Route::post('/users/{user}/reinvite', [Admin\UserController::class, 'reinvite'])->whereNumber('user');
        });
    });
});
