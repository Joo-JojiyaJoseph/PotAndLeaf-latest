<?php

return [

    /*
     * Paths the CORS layer applies to. The SPA hits /api/*; uploads are served
     * from /storage/* (images via <img> don't strictly need CORS, but allowing
     * it avoids surprises with fetch/canvas).
     */
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['*'],

    /*
     * The SPA uses Bearer-token auth (Authorization header), not cookies, so a
     * wildcard origin is safe. If you later switch to cookie/Sanctum sessions,
     * replace '*' with the exact SPA origin(s) and set supports_credentials
     * to true.
     *
     * Example when locking down:
     * 'allowed_origins' => ['https://your-frontend-domain.com'],
     */
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
