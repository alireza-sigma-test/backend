<?php

use Illuminate\Support\Facades\Route;

// This is an API-only service; without this route nginx would serve Laravel's
// stock welcome page at "/", the first thing anyone hits. Point a browsing
// reviewer at the actual entry points instead.
// `docs` is a repo-relative path, not a URL: docs/API.md is not under public/,
// so nginx never serves it — see docker/nginx/default.conf's document root.
Route::get('/', fn () => response()->json([
    'name' => 'Proposal Review API',
    'api' => url('/api'),
    'docs' => 'docs/API.md',
    'health' => url('/up'),
]));
