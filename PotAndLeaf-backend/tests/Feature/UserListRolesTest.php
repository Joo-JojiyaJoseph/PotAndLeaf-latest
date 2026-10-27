<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

it('lists users of the company with the role they hold there', function () {
    $this->createCompanyWithUser(['users.view', 'roles.view']);
    $cashier = Role::create(['name' => 'Cashier', 'slug' => 'cashier-'.Str::random(4), 'is_system' => false]);
    $u = User::factory()->create(['name' => 'Anu', 'is_super_admin' => false, 'is_active' => true]);
    $u->companies()->attach($this->company->id, ['is_default' => true]);
    $u->roles()->attach($cashier->id, ['company_id' => $this->company->id]);

    $rows = collect($this->getJson('/api/users', $this->apiHeaders())->assertOk()->json('data'));

    expect($rows->firstWhere('name', 'Anu')['roles'][0]['name'])->toBe('Cashier');
    expect($rows->firstWhere('id', $this->user->id)['roles'][0]['name'])->toBe('Tester');
    expect($u->hasRole($cashier->slug, $this->company->id))->toBeTrue();
    expect($u->hasRole($cashier->slug, 999))->toBeFalse();
});
