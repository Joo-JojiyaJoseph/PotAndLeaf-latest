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
        foreach (['credit_days', 'credit_limit', 'opening_balance', 'outstanding'] as $k) {
            if (array_key_exists($k, $data) && ($data[$k] === '' || $data[$k] === null)) $data[$k] = 0;
        }
        if (array_key_exists('country', $data) && blank($data['country'])) $data['country'] = 'India';
        unset($data['supplier_code']);

        return DB::transaction(function () use ($supplier, $data) {
            if (array_key_exists('photo', $data)) {
                $data['photo'] = MediaStorage::replace($supplier->photo, $data['photo']);
            }

            return $this->suppliers->update($supplier, $data);
        });
    }
}
