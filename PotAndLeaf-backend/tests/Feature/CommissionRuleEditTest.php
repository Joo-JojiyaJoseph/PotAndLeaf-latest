<?php

/**
 * Commission: product offers and care reminders can be changed; a new role for
 * someone who already has a staff rate changes that rate's kind, never adds a rule.
 */

use App\Models\CommissionPromotion;
use App\Models\CommissionRule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['commission.view', 'commission.manage', 'users.view', 'users.update']);
});

it('changes a product offer and a care reminder', function () {
    $h = $this->apiHeaders();
    $id = $this->postJson('/api/commission/promotions', ['name' => 'Palm push', 'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(10)->toDateString(), 'bonus_per_unit' => 10, 'is_active' => true], $h)->assertCreated()->json('data.id');
    $this->putJson("/api/commission/promotions/{$id}", ['name' => 'Palm push (Nov)', 'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(20)->toDateString(), 'bonus_per_unit' => 15, 'is_active' => false], $h)->assertOk();
    $p = CommissionPromotion::find($id);
    expect($p->name)->toBe('Palm push (Nov)')->and((float) $p->bonus_per_unit)->toBe(15.0)->and((bool) $p->is_active)->toBeFalse()
        ->and(CommissionPromotion::count())->toBe(1);

    $rid = $this->postJson('/api/commission/seasonal-care-rules', ['name' => 'Watering', 'days_after_purchase' => 15,
        'message_template' => 'Hi {customer_name}'], $h)->assertCreated()->json('data.id');
    $this->putJson("/api/commission/seasonal-care-rules/{$rid}", ['name' => 'Watering tips', 'days_after_purchase' => 20,
        'message_template' => 'Hello {customer_name}'], $h)->assertOk()->assertJsonPath('data.days_after_purchase', 20);
});

it('updates the existing staff rate when the role changes, without a duplicate', function () {
    $staff = User::factory()->create();
    $staff->companies()->attach($this->company->id, ['is_default' => true]);
    $sales = Role::firstOrCreate(['slug' => 'salesman'], ['name' => 'Salesman']);
    $super = Role::firstOrCreate(['slug' => 'supervisor'], ['name' => 'Supervisor']);
    $staff->roles()->attach($sales->id, ['company_id' => $this->company->id]);
    $this->postJson('/api/commission/rules', ['user_id' => $staff->id, 'rate_type' => 'percent', 'base_percent' => 2], $this->apiHeaders())->assertOk();

    $this->putJson("/api/users/{$staff->id}", ['name' => $staff->name, 'email' => $staff->email, 'role_id' => $super->id], $this->apiHeaders())->assertOk();
    $rules = CommissionRule::where('user_id', $staff->id)->get();
    expect($rules)->toHaveCount(1)->and((bool) $rules[0]->is_supervisor)->toBeTrue()->and((float) $rules[0]->base_percent)->toBe(2.0);

    $this->putJson("/api/users/{$staff->id}", ['name' => $staff->name, 'email' => $staff->email, 'role_id' => $sales->id], $this->apiHeaders())->assertOk();
    expect(CommissionRule::where('user_id', $staff->id)->count())->toBe(1)
        ->and((bool) CommissionRule::where('user_id', $staff->id)->value('is_supervisor'))->toBeFalse();
});
