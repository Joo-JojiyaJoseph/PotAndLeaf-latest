<?php

namespace App\Actions\Purchases;

use App\Models\Product;
use App\Models\Purchase;
use App\Repositories\Contracts\PurchaseRepositoryInterface;
use App\Models\User;
use App\Services\LocationStockService;
use App\Support\Purchasing\PurchaseCalculator;
use Illuminate\Support\Facades\DB;

class CreatePurchase
{
    public function __construct(
        private readonly PurchaseRepositoryInterface $purchases,
        private readonly PurchaseCalculator $calculator,
        private readonly LocationStockService $locationStock,
    ) {}

    /** @param array<string,mixed> $data */
    public function handle(int|string $companyId, array $data, ?int $userId = null): Purchase
    {
        $computed = $this->calculator->compute(
            $data['items'],
            (bool) ($data['is_interstate'] ?? false),
            (float) ($data['landed_cost_total'] ?? 0),
            (float) ($data['discount_amount'] ?? 0),
        );

        $snapshots = Product::forCompany($companyId)
            ->whereIn('id', collect($data['items'])->pluck('product_id')->filter()->all())
            ->get(['id', 'name', 'hsn_code'])->keyBy('id');

        // The branch or godown the goods are received into (default: the user's own, else the main location).
        $locationId = $this->locationStock->resolveLocationId($companyId, $data['location_id'] ?? null, $userId ? User::find($userId) : null);

        return DB::transaction(function () use ($companyId, $data, $computed, $snapshots, $locationId) {
            $purchase = $this->purchases->create([
                'company_id'           => $companyId,
                'location_id'          => $locationId,
                'supplier_id'       => $data['supplier_id'],
                'purchase_no'       => $this->purchases->nextPurchaseNo($companyId),
                // The supplier's own bill number, typed by the user (never generated).
                'invoice_no'        => filled($data['invoice_no'] ?? null) ? trim($data['invoice_no']) : null,
                'invoice_date'      => $data['invoice_date'] ?? null,
                'purchase_date'     => $data['purchase_date'],
                'is_interstate'     => (bool) ($data['is_interstate'] ?? false),
                'status'            => 'draft',
                'agent_code'        => filled($data['agent_code'] ?? null) ? trim($data['agent_code']) : null,
                'notes'             => $data['notes'] ?? null,
                ...$computed['totals'],
            ]);

            $this->writeItems($purchase, $data['items'], $computed['items'], $snapshots);

            return $purchase->load(['supplier', 'items']);
        });
    }

    /** A price typed on a purchase line: blank or zero means "leave the product's price alone". */
    public static function price(mixed $v): ?float
    {
        return ($v === null || $v === '' || (float) $v <= 0) ? null : round((float) $v, 2);
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
                'mrp'               => self::price($input[$i]['mrp'] ?? null),
                'retail_price'      => self::price($input[$i]['retail_price'] ?? null),
                'wholesale_price'   => self::price($input[$i]['wholesale_price'] ?? null),
                'dealer_price'      => self::price($input[$i]['dealer_price'] ?? null),
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
