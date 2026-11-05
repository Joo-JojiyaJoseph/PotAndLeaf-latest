<?php

/**
 * "Owes you" for a customer:
 *  - A receipt that is not an advance cannot be more than is owed
 *    (on account: what the customer owes; against a bill: what is left on it).
 *  - A sales return on a credit bill takes off only what is still unpaid on
 *    that bill; the rest becomes the customer's advance. "Owes you" never goes
 *    below zero.
 */

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Actions\SalesReturns\ConfirmSalesReturn;
use App\Actions\SalesReturns\CreateSalesReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['receipts.view', 'receipts.create', 'customers.view']);
    $this->product = $this->createProduct(['current_stock' => 50, 'retail_price' => 500, 'gst_rate' => 0]);
    $this->customer = $this->createCustomer(['outstanding' => 0, 'advance_balance' => 0]);
});

function owesCreditSale(object $t, float $qty, float $rate)
{
    $sale = app(CreateSale::class)->handle($t->company->id, [
        'customer_id' => $t->customer->id, 'sale_date' => now()->toDateString(), 'payment_mode' => 'credit', 'is_interstate' => false,
        'amount_paid' => 0,
        'items' => [['product_id' => $t->product->id, 'qty' => $qty, 'rate' => $rate, 'gst_rate' => 0]],
    ], $t->user->id);

    return app(ConfirmSale::class)->handle($sale, $t->user->id);
}

function owesReceipt(object $t, array $extra)
{
    return $t->postJson('/api/customer-receipts', array_merge([
        'customer_id' => $t->customer->id, 'receipt_date' => now()->toDateString(), 'mode' => 'cash',
    ], $extra), $t->apiHeaders());
}

it('refuses on-account money beyond what the customer owes, and lets it go to advance', function () {
    // Owes nothing: ₹1,000 on account is refused (it used to make "Owes you" −₹1,000).
    owesReceipt($this, ['amount' => 1000])->assertStatus(422)->assertJsonValidationErrors(['amount']);
    expect((float) $this->customer->fresh()->outstanding)->toBe(0.0);

    // Ticked as advance: fine.
    owesReceipt($this, ['amount' => 1000, 'is_advance' => true])->assertCreated();
    expect((float) $this->customer->fresh()->advance_balance)->toBe(1000.0);

    // Owes ₹2,000: up to ₹2,000 on account.
    $sale = owesCreditSale($this, 4, 500);
    owesReceipt($this, ['amount' => 2500])->assertStatus(422)
        ->assertJsonFragment(['amount' => [$this->customer->name.' owes you ₹2,000.00. Enter up to ₹2,000.00. Tick “Record as customer advance” to keep extra money as advance.']]);
    owesReceipt($this, ['amount' => 900, 'sale_id' => $sale->id])->assertCreated();
    owesReceipt($this, ['amount' => 1200, 'sale_id' => $sale->id])->assertStatus(422)->assertJsonValidationErrors(['amount']); // only 1,100 left
    owesReceipt($this, ['amount' => 1100])->assertCreated();
    expect((float) $this->customer->fresh()->outstanding)->toBe(0.0);
});

it('a return takes off only what is unpaid on the bill; the rest becomes advance', function () {
    $sale = owesCreditSale($this, 4, 500);                       // owes 2,000
    owesReceipt($this, ['amount' => 1500, 'sale_id' => $sale->id])->assertCreated(); // owes 500

    // Return 2 plants = ₹1,000: only ₹500 was unpaid → owes 0, ₹500 to advance (was: owes max(0, 500 − 1,000) = 0 and ₹500 lost).
    $ret = app(CreateSalesReturn::class)->handle($this->company->id, [
        'sale_id' => $sale->id, 'return_date' => now()->toDateString(),
        'items' => [['sale_item_id' => $sale->items->first()->id, 'qty' => 2]],
    ], $this->user->id);
    $ret = app(ConfirmSalesReturn::class)->handle($ret, $this->user->id);
    $c = $this->customer->fresh();
    expect((float) $c->outstanding)->toBe(0.0)->and((float) $c->advance_balance)->toBe(500.0)
        ->and((float) $ret->fresh()->owes_reduced)->toBe(500.0)->and((float) $ret->fresh()->advance_credited)->toBe(500.0);

    // A confirmed return cannot be cancelled (update 41), so this stays as it is.
});

it('a return on an unpaid credit bill lowers what is owed by its full value', function () {
    owesCreditSale($this, 2, 500);                               // owes 1,000
    $sale = owesCreditSale($this, 4, 500);                       // owes 3,000
    $ret = app(CreateSalesReturn::class)->handle($this->company->id, [
        'sale_id' => $sale->id, 'return_date' => now()->toDateString(),
        'items' => [['sale_item_id' => $sale->items->first()->id, 'qty' => 1]],
    ], $this->user->id);
    app(ConfirmSalesReturn::class)->handle($ret, $this->user->id);
    $c = $this->customer->fresh();
    expect((float) $c->outstanding)->toBe(2500.0)->and((float) $c->advance_balance)->toBe(0.0);
});
