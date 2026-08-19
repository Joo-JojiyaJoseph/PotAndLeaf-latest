<?php

use App\Jobs\SendRentalInvoiceWhatsApp;
use App\Models\CompanySetting;
use App\Models\Rental;
use App\Models\RentalInvoice;
use App\Models\RentalNotificationLog;
use App\Services\RentalNotificationService;
use App\Services\RentalService;
use App\Services\SettingsService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['rental.view', 'rental.bill', 'settings.view', 'settings.update']);
});

function phase4Rental(object $test, array $overrides = []): Rental
{
    $customer = $overrides['customer'] ?? $test->createCustomer(['phone' => '9876543210']);
    unset($overrides['customer']);
    $location = $overrides['location'] ?? $test->createLocation();
    unset($overrides['location']);
    $product = $test->createProduct();

    $rental = Rental::create(array_merge([
        'company_id' => $test->company->id,
        'customer_id' => $customer->id,
        'location_id' => $location->id,
        'rental_no' => 'RNT-'.str_pad((string) (Rental::count() + 1), 6, '0', STR_PAD_LEFT),
        'start_date' => now()->subDays(40)->toDateString(),
        'expected_end_date' => now()->addDays(10)->toDateString(),
        'billing_cycle' => 'monthly',
        'auto_bill' => true,
        'deposit' => 1000,
        'status' => 'active',
        'activated_at' => now()->subDays(38),
    ], $overrides));

    $rental->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'qty' => 2,
        'rate_per_cycle' => 100,
        'returned_qty' => 0,
        'damaged_qty' => 0,
        'missing_qty' => 0,
    ]);

    return $rental->fresh(['items', 'customer']);
}

function setCompanySetting(object $test, string $key, string $value): void
{
    CompanySetting::query()->updateOrCreate(
        ['company_id' => $test->company->id, 'key' => $key],
        ['value' => $value],
    );
}

it('auto-bills active rentals when the billing period is due', function () {
    $rental = phase4Rental($this);
    $service = app(RentalService::class);

    $result = $service->billDueRentals($this->company->id);

    expect($result['billed'])->toBe(1);
    $rental->refresh();
    expect($rental->invoices)->toHaveCount(1);
    expect($rental->last_billed_to)->not->toBeNull();
    expect($rental->next_bill_at)->not->toBeNull();

    $invoice = $rental->invoices->first();
    expect((float) $invoice->amount)->toBe(200.0);
    expect($invoice->due_date->toDateString())->toBe(
        $invoice->period_to->copy()->addDays(app(SettingsService::class)->getInt($this->company->id, 'rental_payment_due_days'))->toDateString()
    );
});

it('skips auto-billing when rental_auto_bill is disabled', function () {
    phase4Rental($this);
    setCompanySetting($this, 'rental_auto_bill', '0');

    $result = app(RentalService::class)->billDueRentals($this->company->id);

    expect($result['billed'])->toBe(0);
    expect($result['skipped'])->toBe(1);
    expect(RentalInvoice::count())->toBe(0);
});

it('queues WhatsApp when auto-billing with rental_whatsapp_on_bill enabled', function () {
    Queue::fake();
    phase4Rental($this);

    app(RentalService::class)->billDueRentals($this->company->id);

    Queue::assertPushed(SendRentalInvoiceWhatsApp::class);
});

it('does not queue WhatsApp when rental_whatsapp_on_bill is disabled', function () {
    Queue::fake();
    phase4Rental($this);
    setCompanySetting($this, 'rental_whatsapp_on_bill', '0');

    app(RentalService::class)->billDueRentals($this->company->id);

    Queue::assertNothingPushed();
});

it('dedupes return overdue alerts to once per day', function () {
    $this->mock(WhatsAppService::class, function ($mock) {
        $mock->shouldReceive('sendMessage')->andReturn(['success' => true, 'message' => 'sent', 'provider' => 'test']);
    });

    $rental = phase4Rental($this, [
        'expected_end_date' => now()->subDays(5)->toDateString(),
    ]);

    $notifications = app(RentalNotificationService::class);
    $first = $notifications->sendOverdueAlerts($this->company->id);
    $second = $notifications->sendOverdueAlerts($this->company->id);

    expect($first['return_alerts'])->toBe(1);
    expect($second['return_alerts'])->toBe(0);
    expect(RentalNotificationLog::query()
        ->where('rental_id', $rental->id)
        ->where('event', 'return_overdue')
        ->whereDate('created_at', now()->toDateString())
        ->count())->toBe(1);
});

it('dedupes payment overdue alerts to once per day', function () {
    $this->mock(WhatsAppService::class, function ($mock) {
        $mock->shouldReceive('sendMessage')->andReturn(['success' => true, 'message' => 'sent', 'provider' => 'test']);
    });

    $rental = phase4Rental($this);
    $invoice = RentalInvoice::create([
        'company_id' => $this->company->id,
        'rental_id' => $rental->id,
        'invoice_no' => 'RINV-000099',
        'period_from' => now()->subDays(40)->toDateString(),
        'period_to' => now()->subDays(11)->toDateString(),
        'cycles' => 1,
        'amount' => 200,
        'due_date' => now()->subDays(3)->toDateString(),
        'status' => 'unpaid',
    ]);

    $notifications = app(RentalNotificationService::class);
    $first = $notifications->sendOverdueAlerts($this->company->id);
    $second = $notifications->sendOverdueAlerts($this->company->id);

    expect($first['payment_alerts'])->toBe(1);
    expect($second['payment_alerts'])->toBe(0);
    expect(RentalNotificationLog::query()
        ->where('rental_invoice_id', $invoice->id)
        ->where('event', 'payment_overdue')
        ->whereDate('created_at', now()->toDateString())
        ->count())->toBe(1);
});

it('exposes rental automation fields on the rental API', function () {
    $rental = phase4Rental($this, [
        'next_bill_at' => now()->addDays(5)->toDateString(),
        'last_billed_to' => now()->subDays(10)->toDateString(),
    ]);

    $this->getJson("/api/rentals/{$rental->id}", $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.auto_bill', true)
        ->assertJsonPath('data.next_bill_at', $rental->next_bill_at->toDateString())
        ->assertJsonPath('data.last_billed_to', $rental->last_billed_to->toDateString());
});

it('returns rental automation settings with defaults', function () {
    $this->getJson('/api/settings', $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.rental_auto_bill', '1')
        ->assertJsonPath('data.rental_whatsapp_on_bill', '1')
        ->assertJsonPath('data.rental_payment_due_days', '7')
        ->assertJsonPath('data.rental_overdue_alert_days', '0');
});
