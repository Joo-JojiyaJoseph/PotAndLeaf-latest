<?php

namespace App\Actions\Purchases;

use App\Models\Purchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Only a draft purchase can be cancelled. A confirmed purchase has already
 * posted stock and a supplier payable, so it is corrected with a return.
 */
class CancelPurchase
{
    public function handle(Purchase $purchase, ?int $userId = null): Purchase
    {
        if ($purchase->isConfirmed()) {
            throw ValidationException::withMessages([
                'status' => 'Confirmed purchases cannot be cancelled. Record a purchase return instead.',
            ]);
        }

        return DB::transaction(function () use ($purchase) {
            $purchase->update(['status' => 'cancelled']);

            return $purchase->refresh();
        });
    }
}
