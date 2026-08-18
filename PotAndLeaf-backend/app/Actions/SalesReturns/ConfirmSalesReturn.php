<?php

namespace App\Actions\SalesReturns;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\SalesReturn;
use App\Repositories\Contracts\SalesReturnRepositoryInterface;
use App\Services\InventoryService;
use App\Services\LoyaltyService;
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
    ) {}

    public function handle(SalesReturn $return, ?int $userId = null): SalesReturn
    {
        if (! $return->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only draft returns can be confirmed.']);
        }

        return DB::transaction(function () use ($return, $userId) {
            $return->loadMissing(['items', 'sale']);
            $this->guardReturnable($return);

            foreach ($return->items as $item) {
                if (! $item->product_id) {
                    continue;
                }

                $product = Product::forCompany($return->company_id)->lockForUpdate()->find($item->product_id);
                if (! $product) {
                    continue;
                }

                $this->inventory->post(
                    product: $product,
                    direction: 'in',
                    qty: (float) $item->qty,
                    unitCost: (float) $item->unit_cost,
                    referenceType: 'sales-return',
                    referenceId: $return->id,
                    note: "Return {$return->return_no}",
                    userId: $userId,
                );
                $product->save();
            }

            if ($return->customer_id) {
                $customer = Customer::forCompany($return->company_id)->lockForUpdate()->find($return->customer_id);
                if ($customer) {
                    $sale = $return->sale;
                    if ($sale && $sale->payment_mode === 'credit') {
                        $customer->outstanding = max(0, (float) $customer->outstanding - (float) $return->grand_total);
                        $customer->save();
                    }

                    $points = $this->loyalty->pointsEarned($return->company_id, (float) $return->grand_total);
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
                }
            }

            $return->update(['status' => 'confirmed', 'confirmed_at' => now()]);

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
