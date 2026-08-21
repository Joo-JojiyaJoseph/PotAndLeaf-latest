<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Block API access for deactivated accounts (existing tokens revoked on deactivate). */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active && ! $user->is_super_admin) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'This account has been deactivated. Contact your administrator.',
            ], 403);
        }

        return $next($request);
    }
}
