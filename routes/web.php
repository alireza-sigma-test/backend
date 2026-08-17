<?php

use Illuminate\Support\Facades\Route;

// Without this, "/" serves Laravel's stock welcome page. `docs` is a repo-relative
// path, not a URL — docs/API.md is not under public/.
Route::get('/', fn () => response()->json([
    'name' => 'Proposal Review API',
    'api' => url('/api'),
    'docs' => 'docs/API.md',
    'health' => url('/up'),
]));
