<?php

/**
 * Authentication & authorization matrix — AUTHZ-001 … AUTHZ-006 + per-role access.
 *
 * Roles sourced from StandardRolesSeeder + EnsureCompanyAdminRole (Administrator).
 *
 * Run: php artisan test tests/Feature/AuthorizationModuleTest.php
 */

use App\Actions\Rbac\EnsureCompanyAdminRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\StandardRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

const AUTHZ_PASSWORD = 'authz-test-password';

beforeEach(function () {
    (new PermissionSeeder)->run();
    (new StandardRolesSeeder)->run();

    $this->company = Company::create([
        'name' => 'Authz HQ',
        'code' => 'AUTH'.Str::upper(Str::random(3)),
        'is_active' => true,
    ]);

    $this->otherCompany = Company::create([
        'name' => 'Authz Branch',
        'code' => 'BR'.Str::upper(Str::random(3)),
        'is_active' => true,
    ]);

    app(EnsureCompanyAdminRole::class)->handle($this->company);
});

function seedRoleUser(object $test, string $roleSlug, ?Company $company = null): User
{
    $company ??= $test->company;
    $role = Role::where('slug', $roleSlug)->firstOrFail();

    $user = User::factory()->create([
        'email' => "{$roleSlug}-".Str::lower(Str::random(4)).'@authz.test',
        'password' => Hash::make(AUTHZ_PASSWORD),
        'is_active' => true,
        'is_super_admin' => false,
    ]);

    $user->companies()->attach($company->id, ['is_default' => true]);
    $user->roles()->attach($role->id, ['company_id' => $company->id]);

    return $user->fresh();
}

function seedAdministratorUser(object $test, ?Company $company = null): User
{
    $company ??= $test->company;

    $user = User::factory()->create([
        'email' => 'admin-'.Str::lower(Str::random(4)).'@authz.test',
        'password' => Hash::make(AUTHZ_PASSWORD),
        'is_active' => true,
        'is_super_admin' => false,
    ]);

    $user->companies()->attach($company->id, ['is_default' => true]);
    app(EnsureCompanyAdminRole::class)->handle($company, $user);

    return $user->fresh();
}

function seedNoPermissionUser(object $test, ?Company $company = null): User
{
    $company ??= $test->company;

    $user = User::factory()->create([
        'email' => 'restricted-'.Str::lower(Str::random(4)).'@authz.test',
        'password' => Hash::make(AUTHZ_PASSWORD),
        'is_active' => true,
        'is_super_admin' => false,
    ]);

    $user->companies()->attach($company->id, ['is_default' => true]);

    return $user->fresh();
}

function authzHeaders(object $test, User $user, int|string|null $companyId = null): array
{
    $companyId ??= $test->company->id;
    $token = $user->createToken('authz')->plainTextToken;

    return [
        'Authorization' => "Bearer {$token}",
        'X-Company-Id' => (string) $companyId,
        'Accept' => 'application/json',
    ];
}

function assertLoginWorks(object $test, User $user): void
{
    $response = $test->postJson('/api/login', [
        'email' => $user->email,
        'password' => AUTHZ_PASSWORD,
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Signed in.')
        ->assertJsonStructure(['data' => ['token', 'user', 'companies']]);
}

function assertListAccess(object $test, User $user, string $uri, string $permission): void
{
    $response = $test->getJson($uri, authzHeaders($test, $user));

    if ($user->hasPermission($permission, $test->company->id)) {
        $response->assertOk();
    } else {
        $response->assertForbidden();
    }
}

function assertMutationAccess(object $test, User $user, string $method, string $uri, string $permission, array $payload = []): void
{
    $headers = authzHeaders($test, $user);
    $response = match (strtoupper($method)) {
        'POST' => $test->postJson($uri, $payload, $headers),
        'PUT' => $test->putJson($uri, $payload, $headers),
        'DELETE' => $test->deleteJson($uri, [], $headers),
        default => throw new InvalidArgumentException("Unsupported method {$method}"),
    };

    if ($user->hasPermission($permission, $test->company->id)) {
        expect($response->status())->not->toBe(403);
    } else {
        $response->assertForbidden();
    }
}

function runRoleAccessMatrix(object $test, User $user, string $roleLabel): void
{
    assertLoginWorks($test, $user);

    assertListAccess($test, $user, '/api/dashboard', 'reports.view');
    assertListAccess($test, $user, '/api/products', 'products.view');
    assertListAccess($test, $user, '/api/customers', 'customers.view');
    assertListAccess($test, $user, '/api/suppliers', 'suppliers.view');
    assertListAccess($test, $user, '/api/purchases', 'purchases.view');
    assertListAccess($test, $user, '/api/sales', 'sales.view');
    assertListAccess($test, $user, '/api/inventory/stock', 'inventory.view');
    assertListAccess($test, $user, '/api/supplier-payments', 'payments.view');
    assertListAccess($test, $user, '/api/users', 'users.view');
    assertListAccess($test, $user, '/api/reports/dashboard', 'reports.view');

    assertMutationAccess($test, $user, 'POST', '/api/customers', 'customers.create', []);
    $customer = $test->createCustomer(['name' => 'Edit Target '.$roleLabel]);
    assertMutationAccess($test, $user, 'PUT', '/api/customers/'.$customer->id, 'customers.update', ['name' => 'Updated Name', 'type' => 'retail']);
    assertMutationAccess($test, $user, 'DELETE', '/api/suppliers/'.$test->createSupplier(['name' => 'Del '.$roleLabel.' '.Str::random(4)])->id, 'suppliers.delete');
}

function projectRoles(): array
{
    return [
        'Administrator' => 'administrator',
        'Manager' => 'manager',
        'Cashier' => 'cashier',
        'Godown Staff' => 'godown-staff',
        'Supervisor' => 'supervisor',
        'Salesman' => 'salesman',
    ];
}

describe('Authorization — role access matrix', function () {

    it('Administrator role access matrix', function () {
        $this->user = seedAdministratorUser($this);
        runRoleAccessMatrix($this, $this->user, 'Administrator');
    })->group('authz', 'role-administrator');

    it('Manager role access matrix', function () {
        $this->user = seedRoleUser($this, 'manager');
        runRoleAccessMatrix($this, $this->user, 'Manager');
    })->group('authz', 'role-manager');

    it('Cashier role access matrix', function () {
        $this->user = seedRoleUser($this, 'cashier');
        runRoleAccessMatrix($this, $this->user, 'Cashier');
    })->group('authz', 'role-cashier');

    it('Godown Staff role access matrix', function () {
        $this->user = seedRoleUser($this, 'godown-staff');
        runRoleAccessMatrix($this, $this->user, 'Godown Staff');
    })->group('authz', 'role-godown-staff');

    it('Supervisor role access matrix', function () {
        $this->user = seedRoleUser($this, 'supervisor');
        runRoleAccessMatrix($this, $this->user, 'Supervisor');
    })->group('authz', 'role-supervisor');

    it('Salesman role access matrix', function () {
        $this->user = seedRoleUser($this, 'salesman');
        runRoleAccessMatrix($this, $this->user, 'Salesman');
    })->group('authz', 'role-salesman');

})->group('authz');

describe('Authorization — AUTHZ scenarios', function () {

    it('AUTHZ-001 authorized user can access permitted API', function () {
        $cashier = seedRoleUser($this, 'cashier');

        $this->getJson('/api/sales', authzHeaders($this, $cashier))
            ->assertOk();

        $this->getJson('/api/customers', authzHeaders($this, $cashier))
            ->assertOk();
    })->group('authz', 'AUTHZ-001');

    it('AUTHZ-002 unauthorized user cannot access restricted API', function () {
        $cashier = seedRoleUser($this, 'cashier');

        $this->getJson('/api/suppliers', authzHeaders($this, $cashier))
            ->assertForbidden();

        $this->getJson('/api/users', authzHeaders($this, $cashier))
            ->assertForbidden();
    })->group('authz', 'AUTHZ-002');

    it('AUTHZ-003 unauthorized user cannot call restricted mutation API', function () {
        $salesman = seedRoleUser($this, 'salesman');
        $supplier = $this->createSupplier(['name' => 'Protected Supplier']);

        $this->postJson('/api/supplier-payments', [
            'supplier_id' => $supplier->id,
            'payment_date' => now()->toDateString(),
            'amount' => 100,
            'mode' => 'cash',
        ], authzHeaders($this, $salesman))->assertForbidden();

        $this->deleteJson('/api/suppliers/'.$supplier->id, [], authzHeaders($this, $salesman))
            ->assertForbidden();
    })->group('authz', 'AUTHZ-003');

    it('AUTHZ-004 user cannot access another company record', function () {
        $manager = seedRoleUser($this, 'manager');

        $foreignProduct = Product::create([
            'company_id' => $this->otherCompany->id,
            'sku' => 'OTHER-'.Str::upper(Str::random(4)),
            'name' => 'Foreign Product',
            'gst_rate' => 0,
            'mrp' => 100,
            'cost_price' => 50,
            'retail_price' => 80,
            'current_stock' => 0,
            'opening_stock' => 0,
            'status' => 'active',
        ]);

        $this->getJson('/api/products/'.$foreignProduct->id, authzHeaders($this, $manager))
            ->assertNotFound();
    })->group('authz', 'AUTHZ-004');

    it('AUTHZ-005 user cannot edit another company record', function () {
        $manager = seedRoleUser($this, 'manager');

        $foreignCustomer = Customer::create([
            'company_id' => $this->otherCompany->id,
            'customer_code' => 'FC-'.Str::upper(Str::random(4)),
            'name' => 'Foreign Customer',
            'type' => 'retail',
            'status' => 'active',
        ]);

        $this->putJson('/api/customers/'.$foreignCustomer->id, [
            'name' => 'Hacked Name',
            'type' => 'retail',
            'status' => 'active',
        ], authzHeaders($this, $manager))->assertNotFound();

        expect($foreignCustomer->fresh()->name)->toBe('Foreign Customer');
    })->group('authz', 'AUTHZ-005');

    it('AUTHZ-006 user cannot delete another company record', function () {
        $manager = seedRoleUser($this, 'manager');

        $foreignSupplier = Supplier::create([
            'company_id' => $this->otherCompany->id,
            'supplier_code' => 'FS-'.Str::upper(Str::random(4)),
            'name' => 'Foreign Supplier',
            'address' => 'Other city',
            'status' => 'active',
        ]);

        $this->deleteJson('/api/suppliers/'.$foreignSupplier->id, [], authzHeaders($this, $manager))
            ->assertNotFound();

        expect(Supplier::find($foreignSupplier->id))->not->toBeNull();
    })->group('authz', 'AUTHZ-006');

    it('user with no permissions is blocked from all module APIs', function () {
        $restricted = seedNoPermissionUser($this);

        assertLoginWorks($this, $restricted);

        foreach ([
            '/api/dashboard',
            '/api/products',
            '/api/customers',
            '/api/suppliers',
            '/api/purchases',
            '/api/sales',
            '/api/inventory/stock',
            '/api/supplier-payments',
            '/api/users',
            '/api/reports/dashboard',
        ] as $uri) {
            $this->getJson($uri, authzHeaders($this, $restricted))->assertForbidden();
        }
    })->group('authz', 'AUTHZ-no-perms');

})->group('authz');

describe('Authorization — role catalog', function () {

    it('project roles are seeded with expected slugs', function () {
        $slugs = Role::query()->orderBy('name')->pluck('slug', 'name');

        expect($slugs->keys()->all())->toContain('Administrator', 'Manager', 'Cashier', 'Godown Staff', 'Supervisor', 'Salesman')
            ->and($slugs['Administrator'])->toBe('administrator')
            ->and($slugs['Manager'])->toBe('manager')
            ->and($slugs['Cashier'])->toBe('cashier')
            ->and($slugs['Godown Staff'])->toBe('godown-staff')
            ->and($slugs['Supervisor'])->toBe('supervisor')
            ->and($slugs['Salesman'])->toBe('salesman');
    })->group('authz', 'roles-catalog');

})->group('authz');
