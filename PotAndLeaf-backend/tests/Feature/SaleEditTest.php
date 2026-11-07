<?php

/** Update 66: a draft bill can be edited; a confirmed bill cannot. */

use App\Actions\Sales\ConfirmSale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['sales.view', 'sales.view_all', 'sales.create', 'sales.confirm']);
    $this->product = $this->createProduct(['current_stock' => 50, 'retail_price' => 500, 'gst_rate' => 0]);
    $this->customer = $this->createCustomer(['outstanding' => 0, 'created_by' => $this->user->id]);
});

function editBody(object $t, float $qty, $paid = 0): array
{
    return [
        'customer_id' => $t->customer->id, 'sale_date' => now()->toDateString(), 'payment_mode' => 'cash', 'is_interstate' => false,
        'amount_paid' => $paid,
        'items' => [['product_id' => $t->product->id, 'qty' => $qty, 'rate' => 500, 'gst_rate' => 0]],
    ];
}

it('edits a draft bill and keeps its number', function () {
    $id = $this->postJson('/api/sales', editBody($this, 2), $this->apiHeaders())->assertCreated()->json('data.id');
    $no = $this->getJson("/api/sales/{$id}", $this->apiHeaders())->json('data.sale_no');
    expect($this->getJson("/api/sales/{$id}", $this->apiHeaders())->json('data.can.edit'))->toBeTrue();

    $res = $this->putJson("/api/sales/{$id}", editBody($this, 3, 500), $this->apiHeaders())->assertOk();
    expect($res->json('data.sale_no'))->toBe($no)
        ->and((float) $res->json('data.grand_total'))->toBe(1500.0)
        ->and((float) $res->json('data.amount_paid'))->toBe(500.0)
        ->and(count($res->json('data.items')))->toBe(1);
});

it('a confirmed bill cannot be edited', function () {
    $id = $this->postJson('/api/sales', editBody($this, 2), $this->apiHeaders())->json('data.id');
    app(ConfirmSale::class)->handle(\App\Models\Sale::find($id), $this->user->id);
    expect($this->getJson("/api/sales/{$id}", $this->apiHeaders())->json('data.can.edit'))->toBeFalse();
    $this->putJson("/api/sales/{$id}", editBody($this, 5), $this->apiHeaders())->assertStatus(422);
});

it('amount paid 0 leaves the bill owed; a walk-in must pay in full', function () {
    $id = $this->postJson('/api/sales', editBody($this, 2, 0), $this->apiHeaders())->json('data.id');
    app(ConfirmSale::class)->handle(\App\Models\Sale::find($id), $this->user->id);
    expect((float) $this->customer->fresh()->outstanding)->toBe(1000.0);

    $walkIn = editBody($this, 1, 0);
    unset($walkIn['customer_id']);
    $this->postJson('/api/sales', $walkIn, $this->apiHeaders())->assertStatus(422)->assertJsonValidationErrors('amount_paid');
});
