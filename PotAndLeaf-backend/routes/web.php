<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| This is an API-only backend — the UI is the standalone React SPA. There is
| no Inertia here. Keep this file free of the starter kit's team/Inertia
| routes; the real application lives in routes/api.php.
*/

Route::get('/', fn () => response()->json([
    'app'    => 'Pot & Leaf ERP API',
    'status' => 'ok',
    'docs'   => 'See routes/api.php — all endpoints are under /api.',
]));
