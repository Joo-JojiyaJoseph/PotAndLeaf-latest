<?php

namespace App\Actions\SalesReturns;

use App\Models\Customer;
use App\Models\LoyaltyLedgerEntry;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Services\InventoryService;
use App\Services\LoyaltyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelSalesReturn
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly LoyaltyService $loyalty,
    ) {}

    public function handle(SalesReturn $return, ?int $userId = null): SalesReturn
    {
        if ($return->isConfirmed()) {
            throw ValidationException::withMessages([
                'status' => 'Confirmed returns cannot be cancelled. Record a correction instead.',
            ]);
        }

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
                        if ($return->owes_reduced !== null || ($sale && $sale->payment_mode === 'credit')) {
                            if ($return->owes_reduced !== null) {
                                // Undo exactly what confirming did. Advance already spent
                                // cannot be taken back, so that part is owed again.
                                $credited = (float) $return->advance_credited;
                                $takeBack = min($credited, max(0, (float) $customer->advance_balance));
                                $customer->advance_balance = round((float) $customer->advance_balance - $takeBack, 2);
                                $customer->outstanding = round((float) $customer->outstanding + (float) $return->owes_reduced + ($credited - $takeBack), 2);
                            } else {
                                // Returns confirmed before update 57.
                                $customer->outstanding = (float) $customer->outstanding + (float) $return->grand_total;
                            }
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
