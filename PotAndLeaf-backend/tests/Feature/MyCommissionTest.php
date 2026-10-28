<?php

use App\Models\CommissionPayout;
use App\Models\CommissionRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    // A salesman: only "View own commission".
    $this->createCompanyWithUser(['sales.view', 'commission.view_own']);
    $this->other = User::factory()->create(['is_super_admin' => false, 'is_active' => true]);
    $this->other->companies()->attach($this->company->id, ['is_default' => true]);
    foreach ([$this->user, $this->other] as $u) {
        CommissionRule::create(['company_id' => $this->company->id, 'user_id' => $u->id, 'rate_type' => 'percent', 'base_percent' => 2, 'is_active' => true]);
        CommissionPayout::create(['company_id' => $this->company->id, 'user_id' => $u->id, 'period' => '2026-09', 'sales_total' => 1000, 'amount' => 20, 'mode' => 'cash', 'payment_date' => '2026-10-01', 'status' => 'paid']);
    }
});

it('lets a salesman see their own rate, payments and monthly statement', function () {
    $rules = $this->getJson('/api/commission/rules', $this->apiHeaders())->assertOk()->json('data');
    expect(collect($rules)->pluck('user_id')->unique()->values()->all())->toBe([$this->user->id]);

    $payouts = $this->getJson('/api/commission/payouts', $this->apiHeaders())->assertOk()->json('data');
    expect(collect($payouts)->pluck('user_id')->unique()->values()->all())->toBe([$this->user->id]);

    $this->getJson('/api/commission/compute?user_id='.$this->user->id.'&period=2026-10', $this->apiHeaders())->assertOk()
        ->assertJsonPath('data.has_rule', true);
    $this->getJson('/api/commission/transactions?user_id='.$this->user->id, $this->apiHeaders())->assertOk();
});

it('does not let a salesman see someone else', function () {
    $this->getJson('/api/commission/compute?user_id='.$this->other->id.'&period=2026-10', $this->apiHeaders())->assertForbidden();
});
