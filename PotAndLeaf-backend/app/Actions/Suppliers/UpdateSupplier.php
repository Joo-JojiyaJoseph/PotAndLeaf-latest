<?php

namespace App\Actions\Suppliers;

use App\Models\Supplier;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use App\Support\Media\MediaStorage;
use Illuminate\Support\Facades\DB;

class UpdateSupplier
{
    public function __construct(
        private readonly SupplierRepositoryInterface $suppliers,
    ) {}

    /** @param array<string,mixed> $data */
    public function handle(Supplier $supplier, array $data): Supplier
    {
        foreach (['credit_limit', 'opening_balance', 'credit_days'] as $k) {
            if (array_key_exists($k, $data) && ($data[$k] === '' || $data[$k] === null)) $data[$k] = 0;
        }
        if (array_key_exists('country', $data) && blank($data['country'])) $data['country'] = 'India';
        // Financial balances are maintained by purchases, payments, returns and
        // advances. A supplier edit must not overwrite them from the form.
        // credit_days is a payment term (not a balance), so it stays editable:
        // it sets each purchase's due date = purchase_date + credit_days.
        unset($data['supplier_code'], $data['outstanding'], $data['advance_balance']);

        return DB::transaction(function () use ($supplier, $data) {
            $locked = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->first() ?? $supplier;

            // The form shows the outstanding still unpaid. Saving it keeps what was
            // already paid: entered amount = still unpaid + already paid.
            if (array_key_exists('opening_left', $data)) {
                $service = app(\App\Services\PaymentService::class);
                $paid = round((float) $locked->opening_balance - $service->openingLeft($locked), 2);
                $data['opening_balance'] = round((float) ($data['opening_left'] ?: 0) + $paid, 2);
            }
            unset($data['opening_left']);

            if (array_key_exists('opening_balance', $data)) {
                $delta = round((float) $data['opening_balance'] - (float) $locked->opening_balance, 2);
                if (abs($delta) >= 0.005) {
                    $data['outstanding'] = round((float) $locked->outstanding + $delta, 2);
                }
            }

            if (array_key_exists('photo', $data)) {
                $data['photo'] = MediaStorage::replace($locked->photo, $data['photo']);
            }

            if (array_key_exists('company_id', $data) && (string) $data['company_id'] !== (string) $supplier->company_id) {
                $hasHistory = \App\Models\Purchase::query()->where('supplier_id', $supplier->id)->exists()
                    || \App\Models\SupplierPayment::query()->where('supplier_id', $supplier->id)->exists();
                abort_if($hasHistory, 422, 'Cannot change company — this supplier has purchase or payment history.');
            }

            $updated = $this->suppliers->update($locked, $data);
            // Opening balance changed: re-work which bills the "on account" payments cleared.
            if (array_key_exists('outstanding', $data)) {
                app(\App\Services\PaymentService::class)->settleOnAccount($updated->fresh());
            }

            return $updated;
        });
    }
}
