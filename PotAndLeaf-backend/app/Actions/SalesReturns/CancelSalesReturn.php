<?php

namespace App\Actions\SalesReturns;

use App\Models\Customer;
use App\Models\LoyaltyLedgerEntry;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Services\InventoryService;
use App\Services\LoyaltyService;
use Illuminate\Support\Facades\DB;

class CancelSalesReturn
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly LoyaltyService $loyalty,
    ) {}

    public function handle(SalesReturn $return, ?int $userId = null): SalesReturn
    {
        return DB::transaction(function () use ($return, $userId) {
            if ($return->isConfirmed()) {
                $return->loadMissing(['items', 'sale']);

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
                        direction: 'out',
                        qty: (float) $item->qty,
                        unitCost: (float) $item->unit_cost,
                        referenceType: 'sales-return-cancel',
                        referenceId: $return->id,
                        note: "Reversal of {$return->return_no}",
                        userId: $userId,
                    );
                    $product->save();
                }

                if ($return->customer_id) {
                    $customer = Customer::forCompany($return->company_id)->lockForUpdate()->find($return->customer_id);
                    if ($customer) {
                        $sale = $return->sale;
                        if ($sale && $sale->payment_mode === 'credit') {
                            $customer->outstanding = (float) $customer->outstanding + (float) $return->grand_total;
                            $customer->save();
                        }

                        $points = $this->loyalty->pointsEarned($return->company_id, (float) $return->grand_total);
                        if ($points > 0) {
                            $customer->refresh();
                            $customer->loyalty_points = (int) $customer->loyalty_points + $points;
                            $customer->save();

                            LoyaltyLedgerEntry::create([
                                'company_id'     => $customer->company_id,
                                'customer_id'    => $customer->id,
                                'type'           => 'earn',
                                'points'         => $points,
                                'balance_after'  => (int) $customer->loyalty_points,
                                'reference_type' => 'sales-return-cancel',
                                'reference_id'   => $return->id,
                                'note'           => "Reversal of {$return->return_no}",
                            ]);
                        }
                    }
                }
            }

            $return->update(['status' => 'cancelled']);

            return $return->refresh();
        });
    }
}
