<?php

namespace App\Services;

use App\Models\Location;
use App\Models\LocationStock;
use Illuminate\Support\Collection;

/**
 * Maintains per-location quantities. This is a breakdown layer that sits
 * alongside the company-level stock ledger — company current_stock remains the
 * source of truth for totals; this tracks where that stock physically sits.
 */
class LocationStockService
{
    public function defaultLocation(int|string $companyId): ?Location
    {
        return Location::forCompany($companyId)->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('name')->first();
    }

    /** Adjust a location's quantity for a product. Direction 'in' adds, 'out' subtracts. */
    public function adjust(int|string $companyId, string $locationId, string $productId, string $direction, float $qty): LocationStock
    {
        $row = LocationStock::firstOrNew(['location_id' => $locationId, 'product_id' => $productId]);
        $row->company_id = $companyId;
        $delta = $direction === 'out' ? -$qty : $qty;
        $row->qty = (float) $row->qty + $delta;
        $row->save();

        return $row;
    }

    public function available(string $locationId, string $productId): float
    {
        return (float) (LocationStock::where('location_id', $locationId)->where('product_id', $productId)->value('qty') ?? 0);
    }

    /** Per-location balances for a company (optionally one location), joined to product + location names. */
    public function balances(int|string $companyId, ?string $locationId = null): Collection
    {
        return LocationStock::forCompany($companyId)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->where('qty', '<>', 0)
            ->with(['product:id,sku,name', 'location:id,name,type'])
            ->get()
            ->map(fn ($r) => [
                'location_id'   => $r->location_id,
                'location_name' => $r->location?->name,
                'product_id'    => $r->product_id,
                'sku'           => $r->product?->sku,
                'product_name'  => $r->product?->name,
                'qty'           => (float) $r->qty,
            ]);
    }
}
