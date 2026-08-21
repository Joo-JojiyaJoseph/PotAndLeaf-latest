<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Token auth for the decoupled SPA (Laravel Sanctum personal access tokens).
 *
 * Requires: composer require laravel/sanctum, and `use HasApiTokens` on the
 * User model. See the API README for the one-time setup.
 */
class AuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = \App\Models\User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Contact your administrator.',
            ]);
        }

        if (! $user->is_super_admin && ! $user->companies()->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This account is not assigned to any company. Contact your administrator.',
            ]);
        }

        $token = $user->createToken('spa')->plainTextToken;

        return $this->ok([
            'token'     => $token,
            'user'      => $this->userPayload($user),
            'companies' => $this->companies($user),
        ], 'Signed in.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok([
            'user'      => $this->userPayload($user),
            'companies' => $this->companies($user),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:150'],
            'email'            => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'            => ['nullable', 'string', 'max:20', 'regex:/^(?=.*\d)\+?[0-9()\-\s]{7,20}$/'],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password'         => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $user->name = $data['name'];
        $user->email = $data['email'];
        if (array_key_exists('phone', $data)) {
            $user->phone = $data['phone'];
        }
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return $this->ok(['user' => $this->userPayload($user->fresh())], 'Profile updated.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->message('Signed out.');
    }

    /** Permission names for the current company, so the SPA can gate its UI. */
    public function permissions(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->is_super_admin) {
            return $this->ok(['*']);
        }

        $company = $request->attributes->get('company');

        return $this->ok(
            $user->permissionNamesForCompany($company->id)->values()
        );
    }

    private function userPayload($user): array
    {
        return [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'phone'          => $user->phone,
            'is_super_admin' => (bool) $user->is_super_admin,
            'is_active'      => (bool) $user->is_active,
        ];
    }

    private function companies($user): array
    {
        if ($user->is_super_admin) {
            return \App\Models\Company::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn ($c) => [
                    'id'         => $c->id,
                    'name'       => $c->name,
                    'code'       => $c->code,
                    'is_default' => false,
                ])
                ->all();
        }

        return $user->companies()
            ->where('companies.is_active', true)
            ->orderBy('name')
            ->get(['companies.id', 'companies.name', 'companies.code'])
            ->map(fn ($c) => [
                'id'         => $c->id,
                'name'       => $c->name,
                'code'       => $c->code,
                'is_default' => (bool) ($c->pivot->is_default ?? false),
            ])
            ->all();
    }
}
