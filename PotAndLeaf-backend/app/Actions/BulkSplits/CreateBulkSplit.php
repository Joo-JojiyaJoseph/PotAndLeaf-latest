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
        $unitCost = (float) $source->cost_price;
        $totalCost = round($sourceQty * $unitCost, 2);

        $allocated = $this->calculator->allocate($totalCost, $data['items']);
        $names = Product::forCompany($companyId)
            ->whereIn('id', collect($data['items'])->pluck('product_id'))
            ->pluck('name', 'id');

        return DB::transaction(function () use ($companyId, $data, $source, $sourceQty, $unitCost, $totalCost, $allocated, $names) {
            $split = $this->splits->create([
                'company_id'          => $companyId,
                'source_product_id'   => $source->id,
                'source_purchase_id'  => $data['source_purchase_id'] ?? null,
                'source_product_name' => $source->name,
                'split_no'            => $this->splits->nextSplitNo($companyId),
                'split_date'          => $data['split_date'],
                'source_qty'          => $sourceQty,
                'source_unit_cost'    => $unitCost,
                'total_cost'          => $totalCost,
                'status'              => 'draft',
                'notes'               => $data['notes'] ?? null,
            ]);

            $rows = [];
            foreach ($allocated as $i => $a) {
                $productId = $data['items'][$i]['product_id'];
                $unitCost = (float) $a['unit_cost'];
                $marginPct = (float) ($data['markup_percent'] ?? 40);
                $suggested = round($unitCost * (1 + $marginPct / 100), 2);
                $retail = isset($data['items'][$i]['retail_price'])
                    ? (float) $data['items'][$i]['retail_price']
                    : $suggested;
                $rows[] = [
                    'product_id'       => $productId,
                    'product_name'     => $names[$productId] ?? 'Item',
                    'qty'              => $a['qty'],
                    'weight'           => $a['weight'],
                    'cost_alloc'       => $a['cost_alloc'],
                    'unit_cost'        => $a['unit_cost'],
                    'suggested_retail' => $suggested,
                    'retail_price'     => $retail,
                ];
            }
            $split->items()->createMany($rows);

            return $split->load(['items', 'sourceProduct:id,sku,name']);
        });
    }
}
