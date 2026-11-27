<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Validation\ValidationException;

/**
 * When a branch needs more than it holds, look at the company's other branches and godowns.
 * Cover as much as possible with stock transfer requests to the branch; the leftover stays
 * a backorder. Stock only moves inside one company.
 */
class ShortageResolutionService
{
    public function __construct(private readonly LocationStockService $locationStock) {}

    /**
     * @param  list<array{product_id: string, ordered_qty: float|int|string, rate?: float|int|string, sale_item_id?: string|null, product_name?: string|null}>  $items
     * @param  array{sale_no?: string|null, notes?: string|null, location_id?: string|null}  $context
     * @return array{
     *     transfers: list<StockTransfer>,
     *     backorderItems: list<array<string, mixed>>,
     *     resolution: list<array<string, mixed>>
     * }
     */
    public function allocate(int|string $companyId, array $items, ?int $userId = null, array $context = []): array
    {
        $dest = ! empty($context['location_id'])
            ? Location::forCompany($companyId)->where('is_active', true)->find($context['location_id'])
            : null;
        $dest ??= $this->locationStock->defaultLocation($companyId);

        // Other branches and godowns that may send stock here (godown to godown is not allowed).
        $sourceLocations = Location::forCompany($companyId)->where('is_active', true)
            ->when($dest, fn ($q) => $q->where('id', '!=', $dest->id))
            ->orderBy('name')->get(['id', 'name', 'type'])
            ->filter(fn (Location $l) => ! ($dest && $dest->type === 'godown' && $l->type === 'godown'))
            ->values();

        $products = Product::forCompany($companyId)
            ->whereIn('id', collect($items)->pluck('product_id')->filter())
            ->get(['id', 'sku', 'name'])
            ->keyBy('id');

        /** @var array<string, float> free stock left per "location|product" while allocating */
        $free = [];
        $allocationsBySource = [];
        $backorderItems = [];
        $resolution = [];

        foreach ($items as $i => $item) {
            $product = $products[$item['product_id']] ?? null;
            $needed = round((float) $item['ordered_qty'], 3);
            if ($needed <= 0) {
                continue;
            }
            if (! $product) {
                throw ValidationException::withMessages(["items.{$i}.product_id" => 'Product not found in this company.']);
            }

            $sources = [];
            foreach ($sourceLocations as $loc) {
                $key = $loc->id.'|'.$product->id;
                if (! isset($free[$key])) {
                    $free[$key] = max(0.0, round(
                        $this->locationStock->available($loc->id, $product->id) - $this->reservedOutbound($loc->id, $product->id),
                        3
                    ));
                }
                if ($free[$key] > 0) {
                    $sources[] = ['location' => $loc, 'atp' => $free[$key]];
                }
            }
            usort($sources, fn ($a, $b) => $b['atp'] <=> $a['atp']);

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

                $allocationsBySource[$source['location']->id][] = ['product_id' => $product->id, 'qty' => $take];
                $free[$source['location']->id.'|'.$product->id] = round($source['atp'] - $take, 3);
                $remaining = round($remaining - $take, 3);
                $transferQty = round($transferQty + $take, 3);
                $sourceNames[] = $source['location']->name;
            }

            if ($remaining > 0) {
                $backorderItems[] = [
                    'product_id'   => $product->id,
                    'sale_item_id' => $item['sale_item_id'] ?? null,
                    'product_name' => $item['product_name'] ?? $product->name,
                    'ordered_qty'  => $remaining,
                    'rate'         => (float) ($item['rate'] ?? 0),
                ];
            }

            $names = array_values(array_unique(array_filter($sourceNames)));
            $resolution[] = [
                'product_id'            => $product->id,
                'product_name'          => $product->name,
                'sku'                   => $product->sku,
                'requested_qty'         => $needed,
                'transfer_qty'          => $transferQty,
                'backorder_qty'         => max(0, $remaining),
                'source_location_names' => $names,
                // Older screens read this key; it now lists the sending branches / godowns.
                'source_company_names'  => $names,
                'action'                => $remaining <= 0 && $transferQty > 0
                    ? 'transfer'
                    : ($transferQty > 0 ? 'split' : 'backorder'),
            ];
        }

        $noteBits = ['Stock request for shortage at '.($dest?->name ?? 'branch')];
        if (! empty($context['sale_no'])) {
            $noteBits[] = 'sale '.$context['sale_no'];
        }
        if (! empty($context['notes'])) {
            $noteBits[] = $context['notes'];
        }
        $notes = implode(' · ', $noteBits);

        $transfers = [];
        foreach ($allocationsBySource as $fromLocationId => $lines) {
            $transfers[] = app(TransferService::class)->create($companyId, [
                'from_location_id' => $fromLocationId,
                'to_location_id'   => $dest->id,
                'transfer_date'    => now()->toDateString(),
                'notes'            => $notes,
                'items'            => $lines,
            ], $userId, autoApprove: false);
        }

        return [
            'transfers'      => $transfers,
            'backorderItems' => $backorderItems,
            'resolution'     => $resolution,
        ];
    }

    /** Qty already promised on open (requested / approved) transfers leaving a location. */
    private function reservedOutbound(string $locationId, string $productId): float
    {
        return (float) StockTransferItem::query()
            ->where('product_id', $productId)
            ->whereHas('stockTransfer', fn ($q) => $q->where('from_location_id', $locationId)->whereIn('status', ['draft', 'requested']))
            ->get()
            ->sum(fn (StockTransferItem $item) => $item->dispatchQty());
    }
}
