<?php

namespace App\Services;

use App\Models\Location;
use App\Models\LocationStock;
use App\Models\Product;
use App\Models\StockTransferItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock per branch / godown.
 *
 * The company's total (products.current_stock) stays the source of truth. Every location
 * except the company's MAIN location (the one marked default) keeps its own row in
 * location_stock. The main location holds whatever is left: total − all other locations.
 *
 * Why: a company with one location behaves exactly as before, and every older flow that does
 * not know about locations (production, rentals, stock counts…) automatically works on the
 * main location. Purchases, bills, returns, damage and transfers that name another location
 * move that location's row as well as the company total.
 */
class LocationStockService
{
    /** @var array<string, Location|null> */
    private array $locations = [];

    public function defaultLocation(int|string $companyId): ?Location
    {
        return Location::forCompany($companyId)->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('name')->first();
    }

    public function location(?string $locationId): ?Location
    {
        if (! $locationId) {
            return null;
        }

        return $this->locations[$locationId] ??= Location::find($locationId);
    }

    /** True when the company has more than one active location (otherwise nothing changes). */
    public function isMultiLocation(int|string $companyId): bool
    {
        return Location::forCompany($companyId)->where('is_active', true)->count() > 1;
    }

    /** The main location holds the remainder of the company total instead of its own row. */
    public function isMain(int|string $companyId, ?string $locationId): bool
    {
        if (! $locationId) {
            return true;
        }

        return (string) $this->defaultLocation($companyId)?->id === (string) $locationId;
    }

    /**
     * Pick the location a document belongs to: the one asked for, else the user's own
     * location in this company, else the company's main location.
     */
    public function resolveLocationId(int|string $companyId, ?string $requested, ?User $user = null): ?string
    {
        if (filled($requested)) {
            $ok = Location::forCompany($companyId)->where('is_active', true)->whereKey($requested)->exists();
            if (! $ok) {
                throw ValidationException::withMessages(['location_id' => 'That location does not belong to this company.']);
            }

            return (string) $requested;
        }

        $own = $user?->locationIdFor($companyId);
        if ($own && Location::forCompany($companyId)->where('is_active', true)->whereKey($own)->exists()) {
            return (string) $own;
        }

        return $this->defaultLocation($companyId)?->id;
    }

    /**
     * Pieces dispatched between locations of the company and not yet received, per product.
     * They have left one location and not reached the next, so no location holds them.
     *
     * @param array<int, string>|null $productIds
     * @return array<string, float>
     */
    public function inTransit(int|string $companyId, ?array $productIds = null): array
    {
        return StockTransferItem::query()
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))
            ->whereHas('stockTransfer', fn ($q) => $q->where('company_id', $companyId)
                ->where('transfer_type', 'intra_company')->where('status', 'in_transit'))
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => round((float) $rows->sum(fn ($r) => $r->dispatchQty()), 3))
            ->all();
    }

    /** Pieces of a product that sit at one location right now. */
    public function available(string $locationId, string $productId): float
    {
        $location = $this->location($locationId);
        if (! $location) {
            return 0.0;
        }

        if ($this->isMain($location->company_id, $location->id)) {
            $total = (float) Product::where('company_id', $location->company_id)->whereKey($productId)->value('current_stock');
            $others = (float) LocationStock::where('company_id', $location->company_id)
                ->where('product_id', $productId)->where('location_id', '!=', $location->id)->sum('qty');
            $moving = (float) ($this->inTransit($location->company_id, [$productId])[$productId] ?? 0);

            return max(0.0, round($total - $others - $moving, 3));
        }

        return (float) (LocationStock::where('location_id', $locationId)->where('product_id', $productId)->value('qty') ?? 0);
    }

    /**
     * Stop a bill / transfer / return that needs more than the location holds.
     * Does nothing for a company with a single location.
     */
    public function assertAvailable(int|string $companyId, ?string $locationId, string $productId, float $qty, string $productName): void
    {
        if (! $locationId || ! $this->isMultiLocation($companyId)) {
            return;
        }

        $have = $this->available($locationId, $productId);
        if ($qty > $have + 0.0001) {
            $name = $this->location($locationId)?->name ?? 'this location';
            throw ValidationException::withMessages([
                'items' => "Not enough stock of {$productName} at {$name}: {$have} available, {$qty} needed. Transfer stock to {$name} first.",
            ]);
        }
    }

    /**
     * Adjust a location's quantity for a product. Direction 'in' adds, 'out' subtracts.
     * The main location has no row of its own (it is the remainder), so nothing is stored for it.
     */
    public function adjust(int|string $companyId, ?string $locationId, string $productId, string $direction, float $qty): ?LocationStock
    {
        if ($qty == 0.0 || $this->isMain($companyId, $locationId)) {
            return null;
        }

        $row = LocationStock::firstOrNew(['location_id' => $locationId, 'product_id' => $productId]);
        $row->company_id = $companyId;
        $delta = $direction === 'out' ? -$qty : $qty;
        $row->qty = round((float) $row->qty + $delta, 3);
        $row->save();

        return $row;
    }

    /**
     * Make another location the company's main one. The old main location gets a row holding
     * what it has now, and the new main location's row is dropped (it becomes the remainder),
     * so no location's quantity changes.
     */
    public function switchMain(int|string $companyId, string $newMainId): void
    {
        $old = $this->defaultLocation($companyId);
        if (! $old || (string) $old->id === (string) $newMainId) {
            return;
        }

        DB::transaction(function () use ($companyId, $old, $newMainId) {
            $others = LocationStock::where('company_id', $companyId)->where('location_id', '!=', $old->id)
                ->selectRaw('product_id, SUM(qty) as s')->groupBy('product_id')->pluck('s', 'product_id');
            $moving = $this->inTransit($companyId);

            foreach (Product::where('company_id', $companyId)->get(['id', 'current_stock']) as $p) {
                $remainder = round((float) $p->current_stock - (float) ($others[$p->id] ?? 0) - (float) ($moving[$p->id] ?? 0), 3);
                if ($remainder != 0.0) {
                    LocationStock::updateOrCreate(
                        ['location_id' => $old->id, 'product_id' => $p->id],
                        ['company_id' => $companyId, 'qty' => $remainder],
                    );
                }
            }

            LocationStock::where('company_id', $companyId)->where('location_id', $newMainId)->delete();
            $this->locations = [];
        });
    }

    /** True if the location still holds any stock (so it cannot be removed or switched off). */
    public function holdsStock(int|string $companyId, string $locationId): bool
    {
        if ($this->isMain($companyId, $locationId)) {
            return Product::where('company_id', $companyId)->where('current_stock', '>', 0)->exists();
        }

        return LocationStock::where('location_id', $locationId)->where('qty', '>', 0)->exists();
    }

    /** Per-location balances for a company (optionally one location), joined to product + location names. */
    public function balances(int|string|null $companyId, ?string $locationId = null): Collection
    {
        // Average purchase cost of the batches still in stock (falls back to product cost price).
        $batchCost = \App\Models\ProductBatch::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('remaining_qty', '>', 0)
            ->selectRaw('product_id, SUM(remaining_qty * cost_price) / SUM(remaining_qty) as avg_cost')
            ->groupBy('product_id')
            ->pluck('avg_cost', 'product_id');

        $row = function (Location $loc, Product $product, float $qty) use ($batchCost): array {
            $cost = (float) ($batchCost[$product->id] ?? $product->cost_price ?? 0);

            return [
                'location_id'   => $loc->id,
                'location_name' => $loc->name,
                'location_type' => $loc->type,
                'product_id'    => $product->id,
                'sku'           => $product->sku,
                'product_name'  => $product->name,
                'qty'           => $qty,
                'reorder_level' => (float) ($product->reorder_level ?? 0),
                'cost_price'    => round($cost, 4),
                'stock_value'   => round($qty * $cost, 2),
            ];
        };

        $mains = Location::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('is_active', true)->where('is_default', true)->get()->keyBy('company_id');
        $mainIds = $mains->pluck('id')->all();

        $out = collect();

        // Locations with their own row (everything except the main location).
        $explicit = LocationStock::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->whereNotIn('location_id', $mainIds)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->where('qty', '<>', 0)
            ->with(['product:id,sku,name,reorder_level,cost_price', 'location:id,name,type'])
            ->get();
        foreach ($explicit as $r) {
            if ($r->product && $r->location) {
                $out->push($row($r->location, $r->product, (float) $r->qty));
            }
        }

        // The main location: company total minus what the other locations hold.
        $sums = LocationStock::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->whereNotIn('location_id', $mainIds)
            ->selectRaw('company_id, product_id, SUM(qty) as s')->groupBy('company_id', 'product_id')->get()
            ->keyBy(fn ($r) => $r->company_id.'|'.$r->product_id);

        $moving = [];
        foreach ($mains->keys() as $cid) {
            foreach ($this->inTransit($cid) as $pid => $q) {
                $moving[$cid.'|'.$pid] = $q;
            }
        }

        $products = Product::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('current_stock', '<>', 0)
            ->get(['id', 'company_id', 'sku', 'name', 'reorder_level', 'cost_price', 'current_stock']);
        foreach ($products as $p) {
            $main = $mains->get($p->company_id);
            if (! $main || ($locationId && (string) $main->id !== (string) $locationId)) {
                continue;
            }
            $qty = round((float) $p->current_stock - (float) ($sums[$p->company_id.'|'.$p->id]->s ?? 0) - (float) ($moving[$p->company_id.'|'.$p->id] ?? 0), 3);
            if ($qty != 0.0) {
                $out->push($row($main, $p, $qty));
            }
        }

        return $out->values();
    }
}
