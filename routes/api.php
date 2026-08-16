<?php

// routes/api.php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\ProposalController;
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

    Route::get('/tags', [TagController::class, 'index']);
    Route::get('/stats', StatsController::class);
});
