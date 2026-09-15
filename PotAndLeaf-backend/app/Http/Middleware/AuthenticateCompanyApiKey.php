<?php

namespace App\Http\Middleware;

use App\Models\CompanyApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Resolve company from a hashed API key — never from a client-supplied company_id. */
class AuthenticateCompanyApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);
        if (! $token || ! str_starts_with($token, 'plk_')) {
            return response()->json(['message' => 'Invalid API credentials.'], 401);
        }

        $key = CompanyApiKey::query()
            ->where('key_hash', CompanyApiKey::hashKey($token))
            ->with('company')
            ->first();

        if (! $key || ! $key->isUsable() || ! $key->company || ! $key->company->is_active) {
            return response()->json(['message' => 'Invalid or revoked API credentials.'], 401);
        }

        $enabled = app(\App\Services\SettingsService::class)->get($key->company_id, 'website_integration');
        if (! in_array((string) $enabled, ['1', 'true'], true)) {
            return response()->json(['message' => 'Website API is disabled for this company.'], 403);
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('company', $key->company);
        $request->attributes->set('company_api_key', $key);
        $request->attributes->set('list_company_id', $key->company_id);

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $header = $request->header('X-Api-Key') ?: $request->bearerToken();

        return $header ? trim($header) : null;
    }
}
