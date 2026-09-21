<?php

namespace App\Services;

use App\Models\BackorderItem;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;

/**
 * When a company requests qty it cannot fulfill locally, look at every other
 * active company for the same SKU. Cover as much as possible with inter-company
 * transfer requests; leftover qty stays a backorder.
 */
class ShortageResolutionService
{
    /** @var array<string, float> */
    private array $atpRemaining = [];

    /** @var array<string, array<string, mixed>> */
    private array $sourceMeta = [];

    /** @var array<string, true> */
    private array $loadedSkus = [];

    /**
     * @param  list<array{product_id: string, ordered_qty: float|int|string, rate?: float|int|string, sale_item_id?: string|null, product_name?: string|null}>  $items
     * @param  array{sale_no?: string|null, notes?: string|null}  $context
     * @return array{
     *     transfers: list<StockTransfer>,
     *     backorderItems: list<array<string, mixed>>,
     *     resolution: list<array<string, mixed>>
     * }
     */
    public function allocate(int|string $destCompanyId, array $items, ?int $userId = null, array $context = []): array
    {
        $this->atpRemaining = [];
        $this->sourceMeta = [];
        $this->loadedSkus = [];

        $destProducts = Product::forCompany($destCompanyId)
            ->whereIn('id', collect($items)->pluck('product_id')->filter())
            ->get(['id', 'sku', 'name', 'current_stock'])
            ->keyBy('id');

        $allocationsBySource = [];
        $backorderItems = [];
        $resolution = [];

        foreach ($items as $i => $item) {
            $destProduct = $destProducts[$item['product_id']] ?? null;
            $needed = round((float) $item['ordered_qty'], 3);
            if ($needed <= 0) {
                continue;
            }
            if (! $destProduct) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "items.{$i}.product_id" => 'Product not found in this company.',
                ]);
            }

            $sources = $this->sourcesForSku($destProduct->sku, $destCompanyId);
            $remaining = $needed;
            $transferQty = 0.0;
            $sourceNames = [];

            $preferred = collect($sources)->first(fn (array $s) => $s['atp'] + 0.0001 >= $remaining);
            $queue = $preferred ? [$preferred] : $sources;

            foreach ($queue as $source) {
                if ($remaining <= 0) {
                    break;
                }
                $take = round(min($remaining, $source['atp']), 3);
                if ($take <= 0) {
                    continue;
                }

                $allocationsBySource[$source['company_id']]['items'][] = [
                    'product_id' => $source['product_id'],
                    'qty'        => $take,
                ];
                $this->atpRemaining[$source['product_id']] = round($source['atp'] - $take, 3);
                $remaining = round($remaining - $take, 3);
                $transferQty = round($transferQty + $take, 3);
                $sourceNames[] = $source['company_name'];
            }

            if ($remaining > 0) {
                $backorderItems[] = [
                    'product_id'   => $destProduct->id,
                    'sale_item_id' => $item['sale_item_id'] ?? null,
                    'product_name' => $item['product_name'] ?? $destProduct->name,
                    'ordered_qty'  => $remaining,
                    'rate'         => (float) ($item['rate'] ?? 0),
                ];
            }

            $resolution[] = [
                'product_id'           => $destProduct->id,
                'product_name'         => $destProduct->name,
                'sku'                  => $destProduct->sku,
                'requested_qty'        => $needed,
                'transfer_qty'         => $transferQty,
                'backorder_qty'        => max(0, $remaining),
                'source_company_names' => array_values(array_unique(array_filter($sourceNames))),
                'action'               => $remaining <= 0 && $transferQty > 0
                    ? 'transfer'
                    : ($transferQty > 0 ? 'split' : 'backorder'),
            ];
        }

        $destName = optional(Company::find($destCompanyId))->name ?? 'destination branch';
        $noteBits = ['Inter-company transfer request for shortage at '.$destName];
        if (! empty($context['sale_no'])) {
            $noteBits[] = 'sale '.$context['sale_no'];
        }
        if (! empty($context['notes'])) {
            $noteBits[] = $context['notes'];
        }
        $notes = implode(' · ', $noteBits);

        $transfers = [];
        foreach ($allocationsBySource as $sourceCompanyId => $alloc) {
            $transfers[] = app(TransferService::class)->create($sourceCompanyId, [
                'to_company_id' => $destCompanyId,
                'transfer_type' => 'inter_company',
                'transfer_date' => now()->toDateString(),
                'notes'         => $notes,
                'items'         => $alloc['items'],
            ], $userId, autoApprove: false);
        }

        return [
            'transfers'      => $transfers,
            'backorderItems' => $backorderItems,
            'resolution'     => $resolution,
        ];
    }

    /**
     * @return list<array{company_id: int|string, company_name: string|null, product_id: string, product_name: string, atp: float}>
     */
    private function sourcesForSku(string $sku, int|string $excludeCompanyId): array
    {
        if (! isset($this->loadedSkus[$sku])) {
            $products = Product::query()
                ->whereRaw('LOWER(TRIM(sku)) = ?', [mb_strtolower(trim($sku))])
                ->where('company_id', '!=', $excludeCompanyId)
                ->whereHas('company', fn ($q) => $q->active())
                ->with('company:id,name,code')
                ->get();

            foreach ($products as $p) {
                $pending = $this->pendingBackorderQty($p->company_id, $p->id);
                $reserved = $this->reservedOutboundQty($p->company_id, $p->id);
                $this->sourceMeta[$p->id] = [
                    'company_id'   => $p->company_id,
                    'company_name' => $p->company?->name,
                    'product_id'   => $p->id,
                    'product_name' => $p->name,
                    'sku'          => $p->sku,
                ];
                $this->atpRemaining[$p->id] = max(0, round((float) $p->current_stock - $pending - $reserved, 3));
            }
            $this->loadedSkus[$sku] = true;
        }

        return collect($this->sourceMeta)
            ->filter(fn (array $meta) => mb_strtolower(trim((string) $meta['sku'])) === mb_strtolower(trim($sku)))
            ->map(fn (array $meta) => [
                'company_id'   => $meta['company_id'],
                'company_name' => $meta['company_name'],
                'product_id'   => $meta['product_id'],
                'product_name' => $meta['product_name'],
                'atp'          => (float) ($this->atpRemaining[$meta['product_id']] ?? 0),
            ])
            ->filter(fn (array $s) => $s['atp'] > 0)
            ->sortByDesc('atp')
            ->values()
            ->all();
    }

    private function pendingBackorderQty(int|string $companyId, string $productId): float
    {
        return (float) BackorderItem::query()
            ->whereHas('backorder', fn ($q) => $q->forCompany($companyId)->whereIn('status', ['open', 'partial']))
            ->where('product_id', $productId)
            ->selectRaw('SUM(ordered_qty - fulfilled_qty - cancelled_qty) as pending')
            ->value('pending') ?: 0.0;
    }

    private function reservedOutboundQty(int|string $companyId, string $productId): float
    {
        $transferIds = StockTransfer::query()
            ->where('company_id', $companyId)
            ->whereIn('status', ['requested', 'draft'])
            ->pluck('id');

        if ($transferIds->isEmpty()) {
            return 0.0;
        }

        return (float) StockTransferItem::query()
            ->whereIn('stock_transfer_id', $transferIds)
            ->where('product_id', $productId)
            ->get()
            ->sum(fn (StockTransferItem $item) => $item->dispatchQty());
    }
}
