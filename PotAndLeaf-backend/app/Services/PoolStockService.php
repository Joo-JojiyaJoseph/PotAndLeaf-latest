<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockLedgerEntry;
use Illuminate\Validation\ValidationException;

/**
 * A "pool" links a set SKU (e.g. a case) with its unit SKU (the individual
 * pieces inside it) so one physical stock count backs both. The unit
 * product's `current_stock` is the sole source of truth ("base units"); the
 * set product's `current_stock` is always the derived
 * floor(unit_stock / units_per_set), refreshed after every pooled movement —
 * so selling loose units immediately shrinks the sets still available, and
 * selling a set consumes the equivalent number of units from the same pool.
 */
class PoolStockService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function isPooled(?Product $product): bool
    {
        return $product && filled($product->pool_group_id) && filled($product->pool_role);
    }

    /** The authoritative unit-role product for a pool (locked for update). */
    public function lockUnitPartner(Product $product): ?Product
    {
        if (! $this->isPooled($product)) {
            return null;
        }
        if ($product->pool_role === 'unit') {
            return $product;
        }

        return Product::forCompany($product->company_id)
            ->where('pool_group_id', $product->pool_group_id)
            ->where('pool_role', 'unit')
            ->lockForUpdate()
            ->first();
    }

    /** Sellable quantity for a pooled product — derived for the `set` role. */
    public function availableStock(Product $product): float
    {
        if (! $this->isPooled($product) || $product->pool_role === 'unit') {
            return (float) $product->current_stock;
        }

        $unit = Product::forCompany($product->company_id)
            ->where('pool_group_id', $product->pool_group_id)
            ->where('pool_role', 'unit')
            ->first();

        if (! $unit) {
            return 0.0;
        }

        $unitsPerSet = (float) $product->units_per_set ?: 1.0;

        return floor((float) $unit->current_stock / $unitsPerSet);
    }

    /**
     * Post an outbound movement (sale, etc.) against a pooled product.
     * Selling a unit deducts 1 base unit directly; selling a set converts to
     * the equivalent unit quantity first. Either way only the unit product's
     * current_stock actually moves — set siblings are refreshed afterwards.
     */
    public function deduct(
        Product $product,
        float $qty,
        string $referenceType,
        ?string $referenceId,
        ?string $note,
        ?int $userId,
    ): void {
        $unit = $this->lockUnitPartner($product);
        if (! $unit) {
            throw ValidationException::withMessages([
                'items' => "{$product->name} is missing its linked pooled stock — cannot sell it.",
            ]);
        }

        $unitsPerSet = $product->pool_role === 'unit' ? 1.0 : ((float) $product->units_per_set ?: 1.0);
        $unitsNeeded = round($qty * $unitsPerSet, 3);

        if ((float) $unit->current_stock < $unitsNeeded) {
            $available = $this->availableStock($product);
            throw ValidationException::withMessages([
                'items' => "Not enough stock for {$product->name}: {$available} available, {$qty} required.",
            ]);
        }

        $this->inventory->post(
            product: $unit, direction: 'out', qty: $unitsNeeded, unitCost: (float) $unit->cost_price,
            referenceType: $referenceType, referenceId: $referenceId,
            note: $product->pool_role === 'unit' ? $note : trim(($note ?? '') . " (as {$qty} × {$product->name})"),
            userId: $userId,
        );
        $unit->save();

        $this->refreshSetMirrors($unit, $referenceType, $referenceId, $note, $userId);
    }

    /**
     * Post an inbound movement (purchase) into the shared pool. Always lands
     * on the unit product as base units; set mirrors are refreshed after.
     */
    public function receive(
        Product $unit,
        float $unitsIn,
        float $unitCostPerUnit,
        string $referenceType,
        ?string $referenceId,
        ?string $note,
        ?int $userId,
    ): void {
        $this->inventory->post(
            product: $unit, direction: 'in', qty: $unitsIn, unitCost: $unitCostPerUnit,
            referenceType: $referenceType, referenceId: $referenceId, note: $note, userId: $userId,
        );
        $unit->cost_price = $unitCostPerUnit;
        $unit->save();

        $this->refreshSetMirrors($unit, $referenceType, $referenceId, $note, $userId);
    }

    /** Recompute every `set`-role sibling's derived stock and drop a ledger note when it changes. */
    private function refreshSetMirrors(Product $unit, string $referenceType, ?string $referenceId, ?string $note, ?int $userId): void
    {
        $sets = Product::forCompany($unit->company_id)
            ->where('pool_group_id', $unit->pool_group_id)
            ->where('pool_role', 'set')
            ->lockForUpdate()
            ->get();

        foreach ($sets as $set) {
            $unitsPerSet = (float) $set->units_per_set ?: 1.0;
            $newAvailable = floor((float) $unit->current_stock / $unitsPerSet);
            $prev = (float) $set->current_stock;
            if (abs($newAvailable - $prev) < 0.0001) {
                continue;
            }

            StockLedgerEntry::create([
                'company_id'     => $set->company_id,
                'product_id'     => $set->id,
                'direction'      => $newAvailable >= $prev ? 'in' : 'out',
                'qty'            => abs($newAvailable - $prev),
                'unit_cost'      => (float) $set->cost_price,
                'balance_after'  => $newAvailable,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'note'           => trim(($note ? "{$note} " : '') . '(derived from shared unit pool)'),
                'occurred_at'    => now(),
                'created_by'     => $userId,
            ]);
            $set->current_stock = $newAvailable;
            $set->save();
        }
    }
}
