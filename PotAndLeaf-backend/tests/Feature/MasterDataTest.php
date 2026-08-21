<?php

use App\Models\Company;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

it('super admin can update a category when company_id is an integer', function () {
    $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
    $this->company = Company::create(['name' => 'Branch', 'code' => 'BR01', 'is_active' => true]);

    $category = ProductCategory::create([
        'company_id'  => $this->company->id,
        'code'        => 'CAT-0001',
        'name'        => 'Plants',
        'status'      => 'active',
    ]);

    $this->actingAs($admin)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->putJson("/api/masters/categories/{$category->id}", [
            'name'        => 'Plants updated',
            'description' => null,
            'status'      => 'active',
            'parent_id'   => null,
            'company_id'  => $this->company->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Plants updated');
});

it('company user can update a unit without sending company_id', function () {
    $this->createCompanyWithUser(['units.view', 'units.update']);

    $unit = ProductUnit::create([
        'company_id'  => $this->company->id,
        'code'        => 'UNIT-0001',
        'name'        => 'Kilogram',
        'short_name'  => 'kg',
        'status'      => 'active',
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->putJson("/api/masters/units/{$unit->id}", [
            'name'        => 'Kilogram updated',
            'short_name'  => 'kg',
            'description' => null,
            'status'      => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Kilogram updated');
});
