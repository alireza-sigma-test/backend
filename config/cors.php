<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // `broadcasting/auth` is not under `api/*` — it is a framework route
    // registered at the root by withBroadcasting() in bootstrap/app.php, and
    // the SPA on another origin has to POST to it before it can join any
    // private channel. Without this entry the browser blocks the request
    // outright and Echo reports a bare "Failed to fetch": no status code, no
    // server-side log line, and nothing a backend test can see, because CORS
    // is enforced in the browser and never reaches PHP. Measured — this is
    // what the first two-browser run actually produced.
    'paths' => ['api/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Unread-Count'],

    'max_age' => 0,

    'supports_credentials' => false,

];
