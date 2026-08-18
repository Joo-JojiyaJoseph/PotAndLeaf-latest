<?php

namespace App\Actions\Purchases;

use App\Models\Product;
use App\Models\Purchase;
use App\Repositories\Contracts\PurchaseRepositoryInterface;
use App\Support\Purchasing\PurchaseCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePurchase
{
    public function __construct(
        private readonly PurchaseRepositoryInterface $purchases,
        private readonly PurchaseCalculator $calculator,
    ) {}

    /** @param array<string,mixed> $data */
    public function handle(Purchase $purchase, array $data, ?int $userId = null): Purchase
    {
        if (! $purchase->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft purchases can be edited.',
            ]);
        }

        $computed = $this->calculator->compute(
            $data['items'],
            (bool) ($data['is_interstate'] ?? false),
            (float) ($data['landed_cost_total'] ?? 0),
        );

        $snapshots = Product::forCompany($purchase->company_id)
            ->whereIn('id', collect($data['items'])->pluck('product_id')->filter()->all())
            ->get(['id', 'name', 'hsn_code'])->keyBy('id');

        return DB::transaction(function () use ($purchase, $data, $computed, $snapshots) {
            $this->purchases->update($purchase, [
                'supplier_id'   => $data['supplier_id'],
                'invoice_no'    => $data['invoice_no'] ?? null,
                'invoice_date'  => $data['invoice_date'] ?? null,
                'purchase_date' => $data['purchase_date'],
                'is_interstate' => (bool) ($data['is_interstate'] ?? false),
                'notes'         => $data['notes'] ?? null,
                ...$computed['totals'],
            ]);

            $purchase->items()->delete();

            $rows = [];
            foreach ($computed['items'] as $i => $line) {
                $productId = $data['items'][$i]['product_id'] ?? null;
                $snapshot = $productId ? $snapshots->get($productId) : null;
                $rows[] = [
                    'product_id'       => $productId,
                    'product_name'     => $snapshot->name ?? 'Item',
                    'hsn_code'         => $snapshot->hsn_code ?? null,
                    'qty'              => $line['qty'],
                    'rate'             => $line['rate'],
                    'discount'         => $line['discount'],
                    'taxable_value'    => $line['taxable_value'],
                    'gst_rate'         => $line['gst_rate'],
                    'cgst_amount'      => $line['cgst_amount'],
                    'sgst_amount'      => $line['sgst_amount'],
                    'igst_amount'      => $line['igst_amount'],
                    'line_total'       => $line['line_total'],
                    'landed_alloc'     => $line['landed_alloc'],
                    'landed_unit_cost' => $line['landed_unit_cost'],
                    'is_bulk'           => (bool) ($data['items'][$i]['is_bulk'] ?? false),
                    'sell_as'           => $data['items'][$i]['sell_as'] ?? null,
                    'units_per_set'     => $data['items'][$i]['units_per_set'] ?? null,
                    'split_product_id'  => $data['items'][$i]['split_product_id'] ?? null,
                    'set_product_id'    => $data['items'][$i]['set_product_id'] ?? null,
                ];
            }
            $purchase->items()->createMany($rows);

            return $purchase->load(['supplier', 'items']);
        });
    }
}
