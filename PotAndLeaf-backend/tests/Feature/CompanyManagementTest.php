<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('super admin can list companies after create', function () {
    $admin = User::where('email', 'admin@potandleaf.test')->first()
        ?? User::factory()->create(['email' => 'admin@potandleaf.test', 'is_super_admin' => true, 'is_active' => true]);

    $this->actingAs($admin)->postJson('/api/companies', [
        'name'      => 'Listed Co',
        'is_active' => true,
    ])->assertCreated();

    $response = $this->actingAs($admin)->getJson('/api/companies');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('name'))->toContain('Listed Co');
});

it('super admin can create a company', function () {
    $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);

    $response = $this->actingAs($admin)->postJson('/api/companies', [
        'name'      => 'New Branch',
        'is_active' => true,
    ]);

    $response->assertCreated();
    expect(Company::where('name', 'New Branch')->exists())->toBeTrue();
});

it('super admin can update a company', function () {
    $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
    $company = Company::create(['name' => 'Old', 'code' => 'OLD1', 'is_active' => true]);

    $response = $this->actingAs($admin)->putJson("/api/companies/{$company->id}", [
        'name'      => 'Updated',
        'is_active' => true,
    ]);

    $response->assertOk();
    expect($company->fresh()->name)->toBe('Updated');
});

it('rejects invalid phone on company save', function () {
    $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);

    $response = $this->actingAs($admin)->postJson('/api/companies', [
        'name'  => 'Bad Phone Co',
        'phone' => 'abc',
    ]);

    $response->assertUnprocessable();
});

it('accepts a long absolute logo url by normalizing to storage path', function () {
    $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
    $longHost = 'https://'.str_repeat('a', 400).'.example.com/storage/uploads/logo.jpg';

    $response = $this->actingAs($admin)->postJson('/api/companies', [
        'name' => 'Logo Co',
        'logo' => $longHost,
    ]);

    $response->assertCreated();
    expect(Company::where('name', 'Logo Co')->value('logo'))->toBe('uploads/logo.jpg');
});

it('super admin can view a company detail with statistics', function () {
    $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
    $company = Company::create(['name' => 'Branch A', 'code' => 'BRAA', 'is_active' => true]);

    $response = $this->actingAs($admin)->getJson("/api/companies/{$company->id}");

    $response->assertOk();
    $response->assertJsonPath('data.name', 'Branch A');
    $response->assertJsonStructure([
        'data' => [
            'id', 'name', 'code', 'statistics' => [
                'users_total', 'products_total', 'suppliers_total', 'purchases_total',
            ],
        ],
    ]);
});

it('non super admin cannot view company detail', function () {
    $user = User::factory()->create(['is_super_admin' => false, 'is_active' => true]);
    $company = Company::create(['name' => 'Branch B', 'code' => 'BRBB', 'is_active' => true]);
    $user->companies()->attach($company->id);

    $this->actingAs($user)
        ->withHeader('X-Company-Id', (string) $company->id)
        ->getJson("/api/companies/{$company->id}")
        ->assertForbidden();
});

it('super admin can move a user to another company', function () {
    $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
    $from = Company::create(['name' => 'From Co', 'code' => 'FROM1', 'is_active' => true]);
    $to = Company::create(['name' => 'To Co', 'code' => 'TOCO1', 'is_active' => true]);
    $user = User::factory()->create(['is_super_admin' => false, 'is_active' => true]);
    $user->companies()->attach($from->id, ['is_default' => true]);

    $response = $this->actingAs($admin)
        ->withHeader('X-Company-Id', (string) $from->id)
        ->putJson("/api/users/{$user->id}", [
            'name'      => $user->name,
            'email'     => $user->email,
            'is_active' => true,
            'target_company_id' => $to->id,
        ]);

    $response->assertOk();
    expect($user->fresh()->companies()->pluck('companies.id')->all())->toBe([$to->id]);
});
