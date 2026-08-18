<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockLedgerEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Owns stock movements. Every change goes through post(), which appends a
 * ledger row carrying the running balance and mutates the product's
 * current_stock in memory (the caller persists inside its own transaction, so
 * a whole purchase posts atomically). Reads for the inventory screens live
 * here too.
 */
class InventoryService
{
    /**
     * Record one movement. Does not open a transaction or save the product —
     * the caller controls both so multi-line documents post atomically.
     */
    public function post(
        Product $product,
        string $direction,
        float $qty,
        ?float $unitCost,
        string $referenceType,
        ?string $referenceId = null,
        ?string $note = null,
        ?int $userId = null,
        ?string $productBatchId = null,
    ): StockLedgerEntry {
        $delta = $direction === 'in' ? $qty : -$qty;
        $newBalance = (float) $product->current_stock + $delta;

        // Never let stock go negative — blocks over-returns, over-issues, and
        // overselling. A tiny epsilon absorbs float rounding.
        if ($direction === 'out' && $newBalance < -0.0001) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'qty' => "Not enough stock for {$product->name}: "
                    . rtrim(rtrim(number_format((float) $product->current_stock, 3, '.', ''), '0'), '.')
                    . ' available, tried to remove '
                    . rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') . '.',
            ]);
        }

        $product->current_stock = $newBalance;

        return StockLedgerEntry::create([
            'company_id'        => $product->company_id,
            'product_id'     => $product->id,
            'product_batch_id' => $productBatchId,
            'direction'      => $direction,
            'qty'            => $qty,
            'unit_cost'      => $unitCost,
            'balance_after'  => $newBalance,
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'note'           => $note,
            'occurred_at'    => now(),
            'created_by'     => $userId,
        ]);
    }

    /** @param array<string,mixed> $filters */
    public function stockLevels(int|string|null $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return Product::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->search($filters['search']))
            ->when(($filters['low_only'] ?? false), fn ($q) => $q->whereColumn('current_stock', '<=', 'reorder_level'))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function reorderAlerts(int|string|null $companyId): \Illuminate\Support\Collection
    {
        return Product::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'current_stock', 'reorder_level']);
    }

    /** @param array<string,mixed> $filters */
    public function ledgerFor(int|string|null $companyId, array $filters = []): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        return $this->ledgerQuery($companyId, $filters)
            ->with([
                'product' => fn ($q) => $q->withTrashed()->select('id', 'sku', 'name', 'company_id'),
                'company:id,name',
            ])
            ->latest('occurred_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Flat list for CSV export (capped). */
    public function ledgerExportRows(int|string|null $companyId, array $filters = [], int $limit = 5000): \Illuminate\Support\Collection
    {
        return $this->ledgerQuery($companyId, $filters)
            ->with([
                'product' => fn ($q) => $q->withTrashed()->select('id', 'sku', 'name', 'company_id'),
                'company:id,name',
            ])
            ->latest('occurred_at')
            ->limit($limit)
            ->get();
    }

    /** @param array<string,mixed> $filters */
    private function ledgerQuery(int|string|null $companyId, array $filters)
    {
        return StockLedgerEntry::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->when(filled($filters['product_id'] ?? null), fn ($q) => $q->where('product_id', $filters['product_id']))
            ->when(filled($filters['reference_type'] ?? null), fn ($q) => $q->where('reference_type', $filters['reference_type']))
            ->when(filled($filters['direction'] ?? null), fn ($q) => $q->where('direction', $filters['direction']))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->whereDate('occurred_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->whereDate('occurred_at', '<=', $filters['to']))
            ->when(filled($filters['search'] ?? null), function ($q) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('note', 'like', $term)
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $term)->orWhere('sku', 'like', $term));
                });
            });
    }

    /** Stock valuation: current_stock × cost_price per product, plus totals. */
    public function valuation(int|string|null $companyId): array
    {
        $rows = Product::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'current_stock', 'cost_price'])
            ->map(fn ($p) => [
                'id'    => $p->id,
                'sku'   => $p->sku,
                'name'  => $p->name,
                'stock' => (float) $p->current_stock,
                'cost'  => (float) $p->cost_price,
                'value' => round((float) $p->current_stock * (float) $p->cost_price, 2),
            ]);

        return [
            'items'  => $rows->values(),
            'totals' => [
                'products'    => $rows->count(),
                'total_units' => round($rows->sum('stock'), 3),
                'total_value' => round($rows->sum('value'), 2),
            ],
        ];
    }

    /**
     * Fast / slow / dead classification by outbound movement over a window.
     * dead = no outbound in the window; fast = outbound at or above the average
     * of the movers; slow = some movement below that average.
     */
    public function movement(int|string|null $companyId, int $days = 30): array
    {
        $since = now()->subDays($days);

        $out = StockLedgerEntry::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->where('direction', 'out')
            ->where('occurred_at', '>=', $since)
            ->selectRaw('product_id, SUM(qty) as out_qty, MAX(occurred_at) as last_out')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $movers = $out->where('out_qty', '>', 0);
        $avg = $movers->count() ? (float) $movers->avg('out_qty') : 0.0;

        $rows = Product::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'current_stock'])
            ->map(function ($p) use ($out, $avg) {
                $outQty = (float) ($out[$p->id]->out_qty ?? 0);
                $class = $outQty <= 0 ? 'dead' : ($outQty >= $avg ? 'fast' : 'slow');
                return [
                    'id'       => $p->id,
                    'sku'      => $p->sku,
                    'name'     => $p->name,
                    'stock'    => (float) $p->current_stock,
                    'out_qty'  => round($outQty, 3),
                    'last_out' => $out[$p->id]->last_out ?? null,
                    'class'    => $class,
                ];
            });

        return [
            'days'    => $days,
            'items'   => $rows->values(),
            'summary' => [
                'fast' => $rows->where('class', 'fast')->count(),
                'slow' => $rows->where('class', 'slow')->count(),
                'dead' => $rows->where('class', 'dead')->count(),
            ],
        ];
    }
}
