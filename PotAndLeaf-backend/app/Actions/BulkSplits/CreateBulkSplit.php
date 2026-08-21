<?php

namespace App\Actions\BulkSplits;

use App\Models\BulkSplit;
use App\Models\Product;
use App\Repositories\Contracts\BulkSplitRepositoryInterface;
use App\Support\Purchasing\BulkSplitCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBulkSplit
{
    public function __construct(
        private readonly BulkSplitRepositoryInterface $splits,
        private readonly BulkSplitCalculator $calculator,
    ) {}

    /** @param array<string,mixed> $data */
    public function handle(int|string $companyId, array $data, ?int $userId = null): BulkSplit
    {
        $source = Product::forCompany($companyId)->find($data['source_product_id']);
        if (! $source) {
            throw ValidationException::withMessages(['source_product_id' => 'Source product not found.']);
        }

        $sourceQty = (float) $data['source_qty'];
        $totalSplitQty = collect($data['items'])->sum(fn ($item) => (float) ($item['qty'] ?? 0));

        if ($totalSplitQty <= 0) {
            throw ValidationException::withMessages(['items' => 'Total split quantity must be greater than zero.']);
        }

        if ($totalSplitQty > $sourceQty) {
            throw ValidationException::withMessages([
                'items' => 'Total split quantity cannot exceed the available bulk quantity.',
            ]);
        }

        if ($sourceQty > (float) $source->current_stock) {
            throw ValidationException::withMessages([
                'source_qty' => "Available quantity cannot exceed stock on hand ({$source->current_stock}).",
            ]);
        }

        $unitCost = (float) $source->cost_price;
        $totalCost = round($totalSplitQty * $unitCost, 2);

        $allocated = $this->calculator->allocate($totalCost, $data['items']);

        $autoCreate = (bool) ($data['auto_create_products'] ?? true);
        $productIds = collect($data['items'])->pluck('product_id')->filter()->values();
        $names = $productIds->isNotEmpty()
            ? Product::forCompany($companyId)->whereIn('id', $productIds)->pluck('name', 'id')
            : collect();

        return DB::transaction(function () use ($companyId, $data, $source, $sourceQty, $unitCost, $totalCost, $totalSplitQty, $allocated, $names, $autoCreate) {
            $split = $this->splits->create([
                'company_id'          => $companyId,
                'source_product_id'   => $source->id,
                'source_purchase_id'  => $data['source_purchase_id'] ?? null,
                'source_product_name' => $source->name,
                'split_no'            => $this->splits->nextSplitNo($companyId),
                'split_date'          => $data['split_date'],
                'source_qty'          => $sourceQty,
                'split_mode'          => $data['split_mode'] ?? null,
                'split_param'         => isset($data['split_param']) ? (float) $data['split_param'] : null,
                'split_total_qty'     => $totalSplitQty,
                'source_unit_cost'    => $unitCost,
                'total_cost'          => $totalCost,
                'status'              => 'draft',
                'notes'               => $data['notes'] ?? null,
            ]);

            $rows = [];
            foreach ($allocated as $i => $a) {
                $line = $data['items'][$i];
                $productId = $line['product_id'] ?? null;
                $sequence = $i + 1;
                $label = $line['split_label'] ?? sprintf('Split %03d', $sequence);
                $marginPct = (float) ($data['markup_percent'] ?? 40);
                $unitCostLine = (float) $a['unit_cost'];
                $suggested = round($unitCostLine * (1 + $marginPct / 100), 2);
                $retail = isset($line['retail_price']) ? (float) $line['retail_price'] : $suggested;

                $productName = $productId
                    ? ($names[$productId] ?? 'Item')
                    : ($source->name.' - '.$label);

                $rows[] = [
                    'product_id'       => $autoCreate ? null : $productId,
                    'product_name'     => $productName,
                    'split_label'      => $label,
                    'split_sequence'   => $sequence,
                    'qty'              => $a['qty'],
                    'weight'           => $a['weight'],
                    'cost_alloc'       => $a['cost_alloc'],
                    'unit_cost'        => $a['unit_cost'],
                    'suggested_retail' => $suggested,
                    'retail_price'     => $retail,
                ];
            }
            $split->items()->createMany($rows);

            return $split->load(['items', 'sourceProduct:id,sku,name,current_stock']);
        });
    }
}
