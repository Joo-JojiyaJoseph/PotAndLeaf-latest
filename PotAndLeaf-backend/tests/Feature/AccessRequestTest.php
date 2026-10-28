<?php

use App\Models\AccessRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

function headersAs(object $t, User $u): array
{
    app('auth')->forgetGuards();

    return $t->apiHeaders($u);
}

beforeEach(function () {
    // The admin of the company (can edit users, sell, see reports).
    $this->createCompanyWithUser(['users.view', 'users.update', 'sales.view', 'reports.view']);
    $this->admin = $this->user;
    // A salesman with very little access.
    $role = Role::create(['name' => 'Seller', 'slug' => 'seller-'.Str::random(4), 'is_system' => false]);
    $role->permissions()->sync(\App\Models\Permission::whereIn('name', ['sales.view'])->pluck('id'));
    $this->seller = User::factory()->create(['is_super_admin' => false, 'is_active' => true]);
    $this->seller->companies()->attach($this->company->id, ['is_default' => true]);
    $this->seller->roles()->attach($role->id, ['company_id' => $this->company->id]);
});

it('lets a user request access and an admin approve part of it', function () {
    $form = $this->getJson('/api/access-requests/form-data', headersAs($this, $this->seller))->assertOk()->json('data.groups');
    $flat = collect($form)->flatMap(fn ($g) => $g['permissions']);
    expect($flat->firstWhere('name', 'sales.view')['has'])->toBeTrue();
    expect($flat->pluck('name'))->not->toContain('*');

    $id = $this->postJson('/api/access-requests', ['permissions' => ['reports.view', 'customers.delete'], 'reason' => 'Need daily sales report'], headersAs($this, $this->seller))
        ->assertCreated()->json('data.id');

    // Not allowed to approve own request or to see the admin list.
    $this->getJson('/api/access-requests', headersAs($this, $this->seller))->assertForbidden();

    $list = $this->getJson('/api/access-requests', headersAs($this, $this->admin))->assertOk()->json('data');
    expect($list[0]['permissions'][0]['can_grant'])->toBeTrue();          // reports.view: admin has it
    expect(collect($list[0]['permissions'])->firstWhere('name', 'customers.delete')['can_grant'])->toBeFalse();

    // Admin cannot give what they do not have.
    $this->postJson("/api/access-requests/{$id}/approve", ['permissions' => ['customers.delete']], headersAs($this, $this->admin))->assertStatus(422);

    $this->postJson("/api/access-requests/{$id}/approve", ['permissions' => ['reports.view'], 'note' => 'ok'], headersAs($this, $this->admin))
        ->assertOk()->assertJsonPath('data.status', 'approved');

    $perms = $this->getJson('/api/permissions', headersAs($this, $this->seller))->assertOk()->json('data');
    expect($perms)->toContain('reports.view')->not->toContain('customers.delete');

    // Admin can take it away again.
    $this->deleteJson("/api/users/{$this->seller->id}/extra-permissions/reports.view", [], headersAs($this, $this->admin))->assertOk();
    expect($this->getJson('/api/permissions', headersAs($this, $this->seller))->json('data'))->not->toContain('reports.view');
});

it('lets the user withdraw a waiting request and blocks asking twice', function () {
    $id = $this->postJson('/api/access-requests', ['permissions' => ['reports.view'], 'reason' => 'reports'], headersAs($this, $this->seller))->assertCreated()->json('data.id');
    $this->postJson('/api/access-requests', ['permissions' => ['reports.view'], 'reason' => 'again'], headersAs($this, $this->seller))->assertStatus(422);
    $this->deleteJson("/api/access-requests/{$id}", [], headersAs($this, $this->seller))->assertOk();
    expect(AccessRequest::find($id)->status)->toBe('cancelled');
});

it('rejects with a note the user can see', function () {
    $id = $this->postJson('/api/access-requests', ['permissions' => ['reports.view'], 'reason' => 'reports'], headersAs($this, $this->seller))->json('data.id');
    $this->postJson("/api/access-requests/{$id}/reject", ['note' => 'Not for your role'], headersAs($this, $this->admin))->assertOk();
    $n = $this->getJson('/api/access-requests/notices', headersAs($this, $this->seller))->assertOk()->json('data.my_answers');
    expect($n[0]['status'])->toBe('rejected')->and($n[0]['decision_note'])->toBe('Not for your role');
});
