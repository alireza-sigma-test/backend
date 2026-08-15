<?php

// routes/api.php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\ProposalController;
use App\Http\Controllers\Api\ReviewController;
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

    Route::post('/proposals', [ProposalController::class, 'store']);
    Route::get('/proposals', [ProposalController::class, 'index']);
    Route::get('/proposals/{proposal}', [ProposalController::class, 'show'])->whereNumber('proposal');
    Route::post('/proposals/{proposal}/reviews', [ReviewController::class, 'store'])->whereNumber('proposal');
    Route::patch('/proposals/{proposal}/status', [StatusController::class, 'update'])->whereNumber('proposal');
    Route::get('/proposals/{proposal}/history', HistoryController::class)->whereNumber('proposal');

    Route::get('/tags', [TagController::class, 'index']);
});
