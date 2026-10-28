<?php

/** Each setting in Settings changes what the app does (checked one by one). */

use App\Models\CompanySetting;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['reports.view']);
});

function setSetting(object $t, string $key, string $value): void
{
    CompanySetting::query()->updateOrCreate(['company_id' => $t->company->id, 'key' => $key], ['value' => $value]);
}

it('starts the cash and bank books from the opening balances', function () {
    setSetting($this, 'cash_opening_balance', '5000');
    setSetting($this, 'bank_opening_balance', '20000');
    $d = now()->toDateString();
    expect(app(ReportService::class)->cashBook($this->company->id, $d, $d)['opening_balance'])->toEqual(5000)
        ->and(app(ReportService::class)->bankBook($this->company->id, $d, $d)['opening_balance'])->toEqual(20000);
});

it('takes daily expense × days off the approx. profit', function () {
    setSetting($this, 'daily_expense', '2000');
    $r = app(ReportService::class)->approximateProfit($this->company->id, now()->subDays(29)->toDateString(), now()->toDateString());
    expect($r['aggregate']['expenses'])->toEqual(60000)->and($r['aggregate']['profit'])->toEqual(-60000);
});

it('sends each company financial-year start month for the This FY report button', function () {
    setSetting($this, 'financial_year_start_month', '1');
    $me = $this->getJson('/api/me', $this->apiHeaders())->assertOk()->json('data.companies');
    expect(collect($me)->firstWhere('id', $this->company->id)['fy_start_month'])->toBe(1);
});
