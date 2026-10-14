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
        foreach (['credit_limit', 'opening_balance'] as $k) {
            if (array_key_exists($k, $data) && ($data[$k] === '' || $data[$k] === null)) $data[$k] = 0;
        }
        if (array_key_exists('country', $data) && blank($data['country'])) $data['country'] = 'India';
        // Financial balances are maintained by purchases, payments, returns and
        // advances. A supplier edit must not overwrite them from the form.
        unset($data['supplier_code'], $data['outstanding'], $data['advance_balance'], $data['credit_days']);

        return DB::transaction(function () use ($supplier, $data) {
            $locked = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->first() ?? $supplier;

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

            return $this->suppliers->update($locked, $data);
        });
    }
}
