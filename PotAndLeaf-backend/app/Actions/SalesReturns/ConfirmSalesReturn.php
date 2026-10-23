<?php

namespace App\Actions\SalesReturns;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\SaleItem;
use App\Models\SalesReturn;
use App\Repositories\Contracts\SalesReturnRepositoryInterface;
use App\Services\CommissionEngine;
use App\Services\InventoryService;
use App\Services\LoyaltyService;
use App\Services\PoolStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirming a sales return: stock back in, reduce customer outstanding on
 * credit sales, reverse a pro-rata loyalty earn.
 */
class ConfirmSalesReturn
{
    public function __construct(
        private readonly SalesReturnRepositoryInterface $returns,
        private readonly InventoryService $inventory,
        private readonly LoyaltyService $loyalty,
        private readonly CommissionEngine $commissionEngine,
        private readonly PoolStockService $pool,
    ) {}

    public function handle(SalesReturn $return, ?int $userId = null): SalesReturn
    {
        if (! $return->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only draft returns can be confirmed.']);
        }

        return DB::transaction(function () use ($return, $userId) {
            // Always reload the full sale: a return handed over straight from
            // CreateSalesReturn carries only sale:id,sale_no, which left
            // payment_mode and company_id blank here.
            $return->load(['items', 'sale']);
            $this->guardReturnable($return);

            foreach ($return->items as $item) {
                if (! $item->product_id) {
                    continue;
                }

                $product = Product::forCompany($return->company_id)->lockForUpdate()->find($item->product_id);
                if (! $product) {
                    continue;
                }

                // Pooled set/unit products go back into the shared unit pool
                // (posting on the set product directly broke its derived stock).
                if ($this->pool->isPooled($product)) {
                    $this->pool->restore(
                        $product, (float) $item->qty, 'sales-return', $return->id, "Return {$return->return_no}", $userId,
                    );

                    continue;
                }

                // If the sold line was scanned to a batch, the goods go back
                // to that batch so batch quantities match stock again.
                $batchId = $item->sale_item_id
                    ? SaleItem::query()->whereKey($item->sale_item_id)->value('product_batch_id')
                    : null;

                $this->inventory->post(
                    product: $product,
                    direction: 'in',
                    qty: (float) $item->qty,
                    unitCost: (float) $item->unit_cost,
                    referenceType: 'sales-return',
                    referenceId: $return->id,
                    note: "Return {$return->return_no}",
                    userId: $userId,
                    productBatchId: $batchId,
                );
                $product->save();

                if ($batchId) {
                    $batch = ProductBatch::query()->whereKey($batchId)->lockForUpdate()->first();
                    if ($batch) {
                        $batch->increment('remaining_qty', (float) $item->qty);
                        if ($batch->status === 'depleted') {
                            $batch->update(['status' => 'active']);
                        }
                    }
                }
            }

            if ($return->customer_id) {
                $customer = Customer::forCompany($return->company_id)->lockForUpdate()->find($return->customer_id);
                if ($customer) {
                    $sale = $return->sale;
                    if ($sale && $sale->payment_mode === 'credit') {
                        $customer->outstanding = max(0, (float) $customer->outstanding - (float) $return->grand_total);
                        $customer->save();
                    }

                    // Take back the share of points this sale really earned
                    // (rules included). Only a return with no sale falls back
                    // to the default earn rate.
                    $points = $sale
                        ? 0
                        : $this->loyalty->pointsEarned($return->company_id, (float) $return->grand_total);
                    if ($sale) {
                        $this->loyalty->reverseEarnForReturn($customer, $sale, $return);
                        $customer->refresh();
                    }
                    if ($points > 0) {
                        $customer->refresh();
                        $customer->loyalty_points = max(0, (int) $customer->loyalty_points - $points);
                        $customer->save();

                        \App\Models\LoyaltyLedgerEntry::create([
                            'company_id'     => $customer->company_id,
                            'customer_id'    => $customer->id,
                            'type'           => 'reverse',
                            'points'         => -$points,
                            'balance_after'  => (int) $customer->loyalty_points,
                            'reference_type' => 'sales-return',
                            'reference_id'   => $return->id,
                            'note'           => "Return {$return->return_no}",
                        ]);
                    }

                    if ($return->sale) {
                        $this->loyalty->reverseRedemptionForReturn($customer, $return->sale, (float) $return->grand_total);
                    }
                }
            }

            $return->update(['status' => 'confirmed', 'confirmed_at' => now()]);

            if ($return->sale) {
                $this->commissionEngine->reverseOnSalesReturn($return->fresh(['items', 'sale']));
            }

            return $return->refresh()->load(['customer', 'sale:id,sale_no', 'items']);
        });
    }

    private function guardReturnable(SalesReturn $return): void
    {
        if (! $return->sale_id) {
            return;
        }

        $confirmed = $this->returns->returnedQtyBySaleItem($return->sale_id, $return->id);
        $origQty = SaleItem::whereIn('id', $return->items->pluck('sale_item_id')->filter())
            ->pluck('qty', 'id');

        foreach ($return->items as $item) {
            $available = (float) ($origQty[$item->sale_item_id] ?? 0)
                - (float) ($confirmed[$item->sale_item_id] ?? 0);

            if ((float) $item->qty > $available + 1e-6) {
                throw ValidationException::withMessages([
                    'items' => "{$item->product_name}: only {$available} left to return.",
                ]);
            }
        }
    }
}
