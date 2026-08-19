<?php

namespace Tests\Support;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Rbac\PermissionRegistry;
use Illuminate\Support\Str;

trait CreatesErpFixtures
{
    public Company $company;

    public User $user;

    public function seedPermissions(): void
    {
        foreach (PermissionRegistry::flat() as $row) {
            Permission::firstOrCreate(
                ['name' => $row['name']],
                ['module' => $row['module'], 'label' => $row['label']],
            );
        }
    }

    public function createCompanyWithUser(array $permissionNames = ['reports.view', 'rental.view', 'sales.view', 'sales.create', 'sales.confirm', 'receipts.view', 'receipts.create']): User
    {
        $this->seedPermissions();

        $this->company = Company::create([
            'name' => 'Test Nursery',
            'code' => 'TST'.Str::upper(Str::random(4)),
            'is_active' => true,
        ]);

        $this->user = User::factory()->create([
            'is_super_admin' => false,
            'is_active' => true,
        ]);

        $this->user->companies()->attach($this->company->id, ['is_default' => true]);

        $role = Role::create([
            'name' => 'Tester',
            'slug' => 'tester-'.Str::lower(Str::random(6)),
            'is_system' => false,
        ]);

        $ids = Permission::whereIn('name', $permissionNames)->pluck('id');
        $role->permissions()->sync($ids);
        $this->user->roles()->attach($role->id, ['company_id' => $this->company->id]);

        return $this->user;
    }

    /** Permissions for cross-module QA matrix scenarios (Phases A–D). */
    public static function qaMatrixPermissions(): array
    {
        return [
            'sales.view', 'sales.create', 'sales.confirm', 'sales.delete',
            'sales.cancel_request', 'sales.cancel_approve', 'sales.whatsapp',
            'backorder.view', 'backorder.create', 'backorder.fulfill', 'backorder.delete',
            'advance.view', 'advance.create', 'advance.fulfill', 'advance.delete',
            'po.view', 'po.create', 'po.send', 'po.convert', 'po.delete',
            'reports.view', 'receipts.view', 'receipts.create',
            'payments.view', 'payments.create',
            'purchases.create', 'purchases.confirm', 'inventory.view',
        ];
    }

    public function createCompanyWithQaUser(): User
    {
        return $this->createCompanyWithUser(self::qaMatrixPermissions());
    }

    public function createSupplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'company_id' => $this->company->id,
            'supplier_code' => 'SUP-'.Str::upper(Str::random(4)),
            'name' => 'Test Supplier',
            'status' => 'active',
            'outstanding' => 0,
            'opening_balance' => 0,
        ], $overrides));
    }

    public function createCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'company_id' => $this->company->id,
            'customer_code' => 'C-'.Str::upper(Str::random(5)),
            'name' => 'Test Customer',
            'type' => 'retail',
            'status' => 'active',
            'outstanding' => 0,
            'loyalty_points' => 0,
        ], $overrides));
    }

    public function createLocation(array $overrides = []): Location
    {
        return Location::create(array_merge([
            'company_id' => $this->company->id,
            'name' => 'Main Godown',
            'code' => 'LOC-'.Str::upper(Str::random(4)),
            'type' => 'godown',
            'is_default' => true,
            'is_active' => true,
        ], $overrides));
    }

    public function createProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'company_id' => $this->company->id,
            'sku' => 'SKU-'.Str::upper(Str::random(5)),
            'name' => 'Areca Palm',
            'gst_rate' => 18,
            'mrp' => 500,
            'cost_price' => 200,
            'retail_price' => 400,
            'wholesale_price' => 350,
            'dealer_price' => 300,
            'current_stock' => 100,
            'opening_stock' => 100,
            'status' => 'active',
        ], $overrides));
    }

    public function apiHeaders(?User $user = null): array
    {
        $user ??= $this->user;
        $token = $user->createToken('test')->plainTextToken;

        return [
            'Authorization' => "Bearer {$token}",
            'X-Company-Id' => (string) $this->company->id,
            'Accept' => 'application/json',
        ];
    }
}
