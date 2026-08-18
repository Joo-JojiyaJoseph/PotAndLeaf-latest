<?php

namespace App\Actions\Purchases;

use App\Models\Product;
use App\Models\Purchase;
use App\Repositories\Contracts\PurchaseRepositoryInterface;
use App\Support\Purchasing\PurchaseCalculator;
use Illuminate\Support\Facades\DB;

class CreatePurchase
{
    public function __construct(
        private readonly PurchaseRepositoryInterface $purchases,
        private readonly PurchaseCalculator $calculator,
    ) {}

    /** @param array<string,mixed> $data */
    public function handle(int|string $companyId, array $data, ?int $userId = null): Purchase
    {
        $computed = $this->calculator->compute(
            $data['items'],
            (bool) ($data['is_interstate'] ?? false),
            (float) ($data['landed_cost_total'] ?? 0),
        );

        $snapshots = Product::forCompany($companyId)
            ->whereIn('id', collect($data['items'])->pluck('product_id')->filter()->all())
            ->get(['id', 'name', 'hsn_code'])->keyBy('id');

        return DB::transaction(function () use ($companyId, $data, $computed, $snapshots) {
            $purchase = $this->purchases->create([
                'company_id'           => $companyId,
                'supplier_id'       => $data['supplier_id'],
                'purchase_no'       => $this->purchases->nextPurchaseNo($companyId),
                'invoice_no'        => $data['invoice_no'] ?? null,
                'invoice_date'      => $data['invoice_date'] ?? null,
                'purchase_date'     => $data['purchase_date'],
                'is_interstate'     => (bool) ($data['is_interstate'] ?? false),
                'status'            => 'draft',
                'notes'             => $data['notes'] ?? null,
                ...$computed['totals'],
            ]);

            $this->writeItems($purchase, $data['items'], $computed['items'], $snapshots);

            return $purchase->load(['supplier', 'items']);
        });
    }

    private function writeItems(Purchase $purchase, array $input, array $computed, $snapshots): void
    {
        $rows = [];
        foreach ($computed as $i => $line) {
            $productId = $input[$i]['product_id'] ?? null;
            $snapshot = $productId ? $snapshots->get($productId) : null;

            $rows[] = [
                'product_id'       => $productId,
                'product_name'     => $snapshot->name ?? ($input[$i]['product_name'] ?? 'Item'),
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
                'is_bulk'           => (bool) ($input[$i]['is_bulk'] ?? false),
                'sell_as'           => $input[$i]['sell_as'] ?? null,
                'units_per_set'     => $input[$i]['units_per_set'] ?? null,
                'split_product_id'  => $input[$i]['split_product_id'] ?? null,
                'set_product_id'    => $input[$i]['set_product_id'] ?? null,
            ];
        }

        $purchase->items()->createMany($rows);
    }
}
