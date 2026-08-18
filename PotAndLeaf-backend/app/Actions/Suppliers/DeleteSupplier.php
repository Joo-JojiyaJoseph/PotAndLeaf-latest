<?php

namespace App\Actions\Suppliers;

use App\Models\Supplier;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use Illuminate\Support\Facades\DB;

class DeleteSupplier
{
    public function __construct(
        private readonly SupplierRepositoryInterface $suppliers,
    ) {}

    public function handle(Supplier $supplier): void
    {
        DB::transaction(function () use ($supplier) {
            // Guard business rules before deletion, e.g. block if the
            // supplier has open purchase bills:
            // if ($supplier->purchaseBills()->exists()) {
            //     throw new BusinessRuleException('Supplier has purchase history.');
            // }

            $this->suppliers->delete($supplier);

            // event(new SupplierDeleted($supplier));
            // activity()->performedOn($supplier)->log('deleted');
        });
    }
}
