<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyApiKey;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyApiKeyController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $this->allow($request, 'api.view');
        $company = $request->attributes->get('company');

        $keys = CompanyApiKey::forCompany($company->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (CompanyApiKey $k) => $this->publicPayload($k));

        return $this->ok($keys);
    }

    public function store(Request $request): JsonResponse
    {
        $this->allow($request, 'api.manage');
        $company = $request->attributes->get('company');
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $plaintext = CompanyApiKey::generatePlaintext();
        $key = CompanyApiKey::create([
            'company_id' => $company->id,
            'name'       => $data['name'],
            'key_prefix' => substr($plaintext, 0, 12),
            'key_hash'   => CompanyApiKey::hashKey($plaintext),
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return $this->created(
            $this->publicPayload($key) + ['api_key' => $plaintext],
            'API key created. Copy it now — it will not be shown again.',
        );
    }

    public function revoke(Request $request, CompanyApiKey $companyApiKey): JsonResponse
    {
        $this->allow($request, 'api.manage');
        abort_unless((string) $companyApiKey->company_id === (string) $request->attributes->get('company')->id, 404);

        $companyApiKey->update(['revoked_at' => now()]);

        return $this->ok($this->publicPayload($companyApiKey->fresh()), 'API key revoked.');
    }

    public function regenerate(Request $request, CompanyApiKey $companyApiKey): JsonResponse
    {
        $this->allow($request, 'api.manage');
        abort_unless((string) $companyApiKey->company_id === (string) $request->attributes->get('company')->id, 404);

        $plaintext = CompanyApiKey::generatePlaintext();
        $companyApiKey->update([
            'key_prefix' => substr($plaintext, 0, 12),
            'key_hash'   => CompanyApiKey::hashKey($plaintext),
            'revoked_at' => null,
        ]);

        return $this->ok(
            $this->publicPayload($companyApiKey->fresh()) + ['api_key' => $plaintext],
            'API key regenerated. Copy it now — it will not be shown again.',
        );
    }

    private function publicPayload(CompanyApiKey $key): array
    {
        return [
            'id'           => $key->id,
            'name'         => $key->name,
            'key_prefix'   => $key->key_prefix.'…',
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'expires_at'   => $key->expires_at?->toDateString(),
            'revoked_at'   => $key->revoked_at?->toIso8601String(),
            'created_at'   => $key->created_at?->toIso8601String(),
            'status'       => $key->revoked_at ? 'revoked' : ($key->isUsable() ? 'active' : 'expired'),
        ];
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $request->attributes->get('company')->id), 403);
    }
}
