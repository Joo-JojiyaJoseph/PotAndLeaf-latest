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
    $this->createCompanyWithUser(['receipts.view', 'receipts.create', 'customers.view', 'customers.update']);
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

function owesSaleMode(object $t, string $mode, float $qty, float $paid)
{
    $sale = app(CreateSale::class)->handle($t->company->id, [
        'customer_id' => $t->customer->id, 'sale_date' => now()->toDateString(), 'payment_mode' => $mode, 'is_interstate' => false,
        'amount_paid' => $paid,
        'items' => [['product_id' => $t->product->id, 'qty' => $qty, 'rate' => 500, 'gst_rate' => 0]],
    ], $t->user->id);

    return app(ConfirmSale::class)->handle($sale, $t->user->id);
}

it('a part-paid cash bill adds the unpaid part to "Owes you" (update 60)', function () {
    $sale = owesSaleMode($this, 'cash', 5, 1000); // ₹2,500 bill, ₹1,000 paid
    expect($sale->paymentStatus())->toBe('partial')
        ->and((float) $this->customer->fresh()->outstanding)->toBe(1500.0);

    // Customer page shows the same unpaid amount
    $this->customer->forceFill(['created_by' => $this->user->id])->save();
    $this->getJson("/api/customers/{$this->customer->id}/purchase-history", $this->apiHeaders())
        ->assertOk()->assertJsonPath('unpaid_bills', 1500);

    // Paying the rest brings it to 0
    owesReceipt($this, ['amount' => 1500, 'sale_id' => $sale->id])->assertCreated();
    expect((float) $this->customer->fresh()->outstanding)->toBe(0.0);
});

it('a fully paid cash bill still adds nothing', function () {
    owesSaleMode($this, 'upi', 2, 1000);
    expect((float) $this->customer->fresh()->outstanding)->toBe(0.0);
});

it('cancelling a part-paid cash bill takes its unpaid part off again', function () {
    $sale = owesSaleMode($this, 'cash', 4, 500); // owes 1,500
    app(\App\Actions\Sales\CancelSale::class)->handle($sale->fresh(), $this->user->id);
    expect((float) $this->customer->fresh()->outstanding)->toBe(0.0);
});

it('a return on a part-paid cash bill lowers what is owed, with no advance', function () {
    $sale = owesSaleMode($this, 'cash', 4, 1000); // ₹2,000 bill, owes ₹1,000
    $ret = app(CreateSalesReturn::class)->handle($this->company->id, [
        'sale_id' => $sale->id, 'return_date' => now()->toDateString(),
        'items' => [['sale_item_id' => $sale->items->first()->id, 'qty' => 1]],
    ], $this->user->id);
    app(ConfirmSalesReturn::class)->handle($ret, $this->user->id);
    $c = $this->customer->fresh();
    expect((float) $c->outstanding)->toBe(500.0)->and((float) $c->advance_balance)->toBe(0.0);
});

it('spots a customer whose "Owes you" does not match the bills, and fixes it', function () {
    $this->customer->forceFill(['created_by' => $this->user->id])->save();

    $sale = owesSaleMode($this, 'cash', 4, 950); // ₹2,000 bill, ₹1,050 unpaid → owes 1,050
    // Simulate an old bill: "Owes you" was never raised.
    $this->customer->fresh()->forceFill(['outstanding' => 0])->save();

    $res = $this->getJson("/api/customers/{$this->customer->id}/purchase-history", $this->apiHeaders())->assertOk();
    expect($res->json('owes_check.matches'))->toBeFalse()->and((float) $res->json('owes_check.expected'))->toBe(1050.0);

    $this->postJson("/api/customers/{$this->customer->id}/recalculate-owes", [], $this->apiHeaders())
        ->assertOk()->assertJsonPath('data.matches', true);
    expect((float) $this->customer->fresh()->outstanding)->toBe(1050.0);

    // Money on account is taken into the working.
    owesReceipt($this, ['amount' => 50])->assertCreated();
    $res = $this->getJson("/api/customers/{$this->customer->id}/purchase-history", $this->apiHeaders());
    expect($res->json('owes_check.matches'))->toBeTrue()->and((float) $res->json('owes_check.expected'))->toBe(1000.0);
});

it('on-account money pays the outstanding from before first, then the oldest bills (update 62)', function () {
    $this->customer->fresh()->forceFill(['opening_balance' => 300, 'outstanding' => 300])->save();
    $old = owesCreditSale($this, 2, 500);  // ₹1,000
    $new = owesCreditSale($this, 1, 500);  // ₹500
    expect((float) $this->customer->fresh()->outstanding)->toBe(1800.0);

    owesReceipt($this, ['amount' => 1100])->assertCreated(); // 300 old + 800 to oldest bill
    $c = $this->customer->fresh();
    expect((float) $c->outstanding)->toBe(700.0)
        ->and((float) $old->fresh()->amount_paid)->toBe(800.0)
        ->and((float) $new->fresh()->amount_paid)->toBe(0.0)
        ->and(app(\App\Services\ReceiptService::class)->openingLeft($c))->toBe(0.0)
        ->and(app(\App\Services\ReceiptService::class)->owesFromRecords($c)['matches'])->toBeTrue();

    // Voiding it puts everything back.
    $receipt = \App\Models\CustomerReceipt::where('customer_id', $this->customer->id)->latest('created_at')->first();
    app(\App\Services\ReceiptService::class)->delete($receipt);
    $c = $this->customer->fresh();
    expect((float) $c->outstanding)->toBe(1800.0)
        ->and((float) $old->fresh()->amount_paid)->toBe(0.0)
        ->and(app(\App\Services\ReceiptService::class)->openingLeft($c))->toBe(300.0);
});

it('a return on a paid cash bill goes to the customer advance, and pays a later bill (update 63)', function () {
    $sale = owesSaleMode($this, 'cash', 2, 1000); // paid in full
    $ret = app(CreateSalesReturn::class)->handle($this->company->id, [
        'sale_id' => $sale->id, 'return_date' => now()->toDateString(),
        'items' => [['sale_item_id' => $sale->items->first()->id, 'qty' => 1]],
    ], $this->user->id);
    app(ConfirmSalesReturn::class)->handle($ret, $this->user->id);
    $c = $this->customer->fresh();
    expect((float) $c->advance_balance)->toBe(500.0)->and((float) $c->outstanding)->toBe(0.0)
        ->and((float) $ret->fresh()->advance_credited)->toBe(500.0);

    // Next bill ₹1,500: ₹500 from advance, ₹1,000 cash (blank = the rest).
    $next = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $this->customer->id, 'sale_date' => now()->toDateString(), 'payment_mode' => 'cash', 'is_interstate' => false,
        'use_advance' => 500,
        'items' => [['product_id' => $this->product->id, 'qty' => 3, 'rate' => 500, 'gst_rate' => 0]],
    ], $this->user->id);
    expect((float) $next->amount_paid)->toBe(1000.0);
    $next = app(ConfirmSale::class)->handle($next, $this->user->id);
    $c = $this->customer->fresh();
    expect((float) $c->advance_balance)->toBe(0.0)->and((float) $c->outstanding)->toBe(0.0)
        ->and((float) $next->fresh()->amount_paid)->toBe(1500.0)
        ->and($next->fresh()->paymentStatus())->toBe('paid');

    // Cancelling that bill gives the advance back.
    app(\App\Actions\Sales\CancelSale::class)->handle($next->fresh(), $this->user->id);
    expect((float) $this->customer->fresh()->advance_balance)->toBe(500.0)
        ->and((float) $this->customer->fresh()->outstanding)->toBe(0.0);
});

it('cannot use more advance than the customer has', function () {
    expect(fn () => app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $this->customer->id, 'sale_date' => now()->toDateString(), 'payment_mode' => 'cash', 'is_interstate' => false,
        'use_advance' => 100,
        'items' => [['product_id' => $this->product->id, 'qty' => 1, 'rate' => 500, 'gst_rate' => 0]],
    ], $this->user->id))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('a credit bill with goods brought back asks only for what is left (update 65)', function () {
    $sale = owesCreditSale($this, 4, 500); // ₹2,000 unpaid
    $ret = app(CreateSalesReturn::class)->handle($this->company->id, [
        'sale_id' => $sale->id, 'return_date' => now()->toDateString(),
        'items' => [['sale_item_id' => $sale->items->first()->id, 'qty' => 1]],
    ], $this->user->id);
    app(ConfirmSalesReturn::class)->handle($ret, $this->user->id); // ₹500 back

    $res = (new \App\Http\Resources\SaleResource($sale->fresh()))->toArray(request());
    expect($res['returned'])->toBe(500.0)->and($res['balance'])->toBe(1500.0)->and($res['payment_status'])->toBe('unpaid');
    $row = collect(app(\App\Services\ReceiptService::class)->receivables($this->company->id, $this->customer->id))->firstWhere('id', $sale->id);
    expect($row['returned'])->toBe(500.0)->and($row['balance'])->toBe(1500.0);
    expect((float) $this->customer->fresh()->outstanding)->toBe(1500.0);

    owesReceipt($this, ['amount' => 2000, 'sale_id' => $sale->id])->assertStatus(422);
    owesReceipt($this, ['amount' => 1500, 'sale_id' => $sale->id])->assertCreated();
    $res = (new \App\Http\Resources\SaleResource($sale->fresh()))->toArray(request());
    expect($res['balance'])->toBe(0.0)->and($res['payment_status'])->toBe('paid')
        ->and((float) $this->customer->fresh()->outstanding)->toBe(0.0);
});

it('lists every change to the customer advance with a running balance (update 67)', function () {
    $this->customer->fresh()->forceFill(['advance_balance' => 100, 'created_by' => $this->user->id])->save(); // from before the records
    owesReceipt($this, ['amount' => 700, 'is_advance' => true])->assertCreated();
    $sale = owesSaleMode($this, 'cash', 2, 1000); // paid cash bill
    $ret = app(CreateSalesReturn::class)->handle($this->company->id, [
        'sale_id' => $sale->id, 'return_date' => now()->toDateString(),
        'items' => [['sale_item_id' => $sale->items->first()->id, 'qty' => 1]],
    ], $this->user->id);
    app(ConfirmSalesReturn::class)->handle($ret, $this->user->id); // +500 to advance
    $credit = owesCreditSale($this, 1, 500);
    app(\App\Services\ReceiptService::class)->applyAdvance($this->company->id, ['customer_id' => $this->customer->id, 'sale_id' => $credit->id, 'amount' => 300], $this->user->id);

    $res = $this->getJson("/api/customers/{$this->customer->id}/advance-history", $this->apiHeaders())->assertOk();
    $rows = collect($res->json('data.rows'));
    expect((float) $res->json('data.balance'))->toBe(1000.0)          // 100 + 700 + 500 − 300
        ->and((float) $rows->first()['balance'])->toBe(1000.0)          // newest first
        ->and($rows->pluck('label')->all())->toContain('Advance received', 'Sales return', 'Used on bill', 'Earlier balance / adjustment')
        ->and((float) $res->json('data.total_out'))->toBe(300.0);
});
