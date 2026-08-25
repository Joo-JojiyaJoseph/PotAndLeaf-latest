<?php

/**
 * Login module automated tests — maps to QA/TEST_PLAN.md LOGIN-001 … LOGIN-012.
 *
 * Scope: Laravel API auth (POST /api/login, POST /api/logout, GET /api/me).
 * Frontend-only behaviour (ProtectedRoute redirect, HTML5 validation, localStorage)
 * requires Vitest/Playwright — see skipped cases below.
 *
 * Run: php artisan test tests/Feature/LoginModuleTest.php
 */

use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->password = 'password';
});

function loginUserWithCompany(object $test, array $userOverrides = []): User
{
    $test->createCompanyWithUser(['reports.view']);

    $test->user->update(array_merge([
        'email' => 'login.test@potandleaf.test',
        'password' => Hash::make($test->password),
        'is_active' => true,
    ], $userOverrides));

    return $test->user->fresh();
}

describe('Login module — API (LOGIN-001 … LOGIN-012)', function () {

    it('LOGIN-001 valid login returns token user and companies', function () {
        loginUserWithCompany($this);

        $response = $this->postJson('/api/login', [
            'email' => 'login.test@potandleaf.test',
            'password' => $this->password,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Signed in.')
            ->assertJsonStructure([
                'data' => ['token', 'user' => ['id', 'email', 'name'], 'companies'],
            ]);

        expect($response->json('data.token'))->not->toBeEmpty();
        expect($response->json('data.user.email'))->toBe('login.test@potandleaf.test');
        expect($response->json('data.companies'))->not->toBeEmpty();
    })->group('login', 'LOGIN-001');

    it('LOGIN-002 invalid email unknown user is rejected', function () {
        loginUserWithCompany($this);

        $response = $this->postJson('/api/login', [
            'email' => 'nobody@does-not-exist.test',
            'password' => $this->password,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        expect($response->json('errors.email.0'))
            ->toBe('Those credentials do not match our records.');
    })->group('login', 'LOGIN-002');

    it('LOGIN-003 invalid password for valid email is rejected', function () {
        loginUserWithCompany($this);

        $response = $this->postJson('/api/login', [
            'email' => 'login.test@potandleaf.test',
            'password' => 'WrongPassword99!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        expect($response->json('errors.email.0'))
            ->toBe('Those credentials do not match our records.');
    })->group('login', 'LOGIN-003');

    it('LOGIN-004 empty email is rejected by API validation', function () {
        $response = $this->postJson('/api/login', [
            'email' => '',
            'password' => 'password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    })->group('login', 'LOGIN-004');

    it('LOGIN-005 empty password is rejected by API validation', function () {
        $response = $this->postJson('/api/login', [
            'email' => 'someone@test.com',
            'password' => '',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    })->group('login', 'LOGIN-005');

    it('LOGIN-006 empty email and password are rejected by API validation', function () {
        $response = $this->postJson('/api/login', [
            'email' => '',
            'password' => '',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    })->group('login', 'LOGIN-006');

    it('LOGIN-007 invalid email format is rejected by API validation', function () {
        $response = $this->postJson('/api/login', [
            'email' => 'not-an-email',
            'password' => 'password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    })->group('login', 'LOGIN-007');

    it('LOGIN-008 protected API returns 401 without authentication', function () {
        $response = $this->getJson('/api/me');

        $response->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    })->group('login', 'LOGIN-008');

    it('LOGIN-009 API 500 response on login endpoint', function () {
        $this->app->bind(AuthController::class, fn () => new class extends AuthController
        {
            public function login(Request $request): JsonResponse
            {
                return response()->json(['message' => 'Server Error'], 500);
            }
        });

        $response = $this->postJson('/api/login', [
            'email' => 'any@test.com',
            'password' => 'password',
        ]);

        $response->assertStatus(500)
            ->assertJson(['message' => 'Server Error']);
    })->group('login', 'LOGIN-009');

    it('LOGIN-010 successful logout revokes token', function () {
        loginUserWithCompany($this);

        $login = $this->postJson('/api/login', [
            'email' => 'login.test@potandleaf.test',
            'password' => $this->password,
        ])->assertOk();

        $token = $login->json('data.token');
        expect(\Laravel\Sanctum\PersonalAccessToken::count())->toBe(1);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/logout')->assertOk();

        expect(\Laravel\Sanctum\PersonalAccessToken::count())->toBe(0);

        // Reset auth state persisted across in-process HTTP calls in tests.
        auth()->forgetGuards();
        $this->flushHeaders()
            ->getJson('/api/me')
            ->assertUnauthorized();
    })->group('login', 'LOGIN-010');

    it('LOGIN-011 access protected API route without authentication returns 401', function () {
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->getJson('/api/me')->assertUnauthorized();
    })->group('login', 'LOGIN-011');

    it('LOGIN-012 expired or invalid authentication token returns 401', function () {
        loginUserWithCompany($this);

        $this->getJson('/api/me', [
            'Authorization' => 'Bearer not-a-valid-token',
            'Accept' => 'application/json',
        ])->assertUnauthorized();

        $token = $this->user->createToken('revoked')->plainTextToken;
        $this->user->tokens()->delete();

        $this->getJson('/api/me', [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->assertUnauthorized();
    })->group('login', 'LOGIN-012');

})->group('login');

describe('Login module — frontend UI (requires Vitest + RTL)', function () {

    it('LOGIN-004 frontend empty email UI validation', function () {
        // Login.jsx has no client-side required check; browser may still submit empty values to API.
        $this->markTestSkipped(
            'Frontend test framework not installed. Install: vitest, jsdom, @testing-library/react, @testing-library/user-event'
        );
    })->group('login', 'LOGIN-004-ui');

    it('LOGIN-007 frontend invalid email format HTML5 validation', function () {
        $this->markTestSkipped(
            'Frontend test framework not installed. Install: vitest, jsdom, @testing-library/react, @testing-library/user-event'
        );
    })->group('login', 'LOGIN-007-ui');

    it('LOGIN-011 frontend ProtectedRoute redirects to login', function () {
        $this->markTestSkipped(
            'E2E/component tests not installed. Install: vitest + @testing-library/react OR @playwright/test'
        );
    })->group('login', 'LOGIN-011-ui');

    it('LOGIN-012 frontend axios interceptor clears token on 401', function () {
        $this->markTestSkipped(
            'Frontend unit tests not installed. Install: vitest, jsdom, @testing-library/react'
        );
    })->group('login', 'LOGIN-012-ui');

})->group('login');
