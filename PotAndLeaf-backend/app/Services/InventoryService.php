<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockLedgerEntry;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Owns stock movements. Every change goes through post(), which appends a
 * ledger row carrying the running balance and mutates the product's
 * current_stock in memory (the caller persists inside its own transaction, so
 * a whole purchase posts atomically). Reads for the inventory screens live
 * here too.
 */
class InventoryService
{
    public function __construct(
        private readonly BackorderService $backorders,
    ) {}

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

    /**
     * Stock availability for a SKU across companies the user may access.
     *
     * @return array{sku: string, product_name: string|null, branches: list<array<string,mixed>>}
     */
    public function crossBranchStock(User $user, int|string $currentCompanyId, ?string $sku = null, ?string $productId = null): array
    {
        if (blank($sku) && blank($productId)) {
            throw ValidationException::withMessages(['sku' => 'Provide sku or product_id.']);
        }

        $anchor = Product::forCompany($currentCompanyId)
            ->when(filled($productId), fn ($q) => $q->whereKey($productId))
            ->when(filled($sku), fn ($q) => $q->where('sku', $sku))
            ->first();

        if (! $anchor) {
            throw ValidationException::withMessages(['sku' => 'Product not found in the current company.']);
        }

        $sku = $anchor->sku;
        $companyIds = $this->accessibleCompanyIds($user, $currentCompanyId);

        $products = Product::query()
            ->whereIn('company_id', $companyIds)
            ->where('sku', $sku)
            ->with('company:id,name,code')
            ->get();

        $branches = $products->map(function (Product $p) use ($currentCompanyId, $sku) {
            $pending = $this->backorders->pendingQtyForProduct($p->company_id, $p->id);
            $inTransitIn = $this->inTransitQty($p->company_id, $sku, 'in');
            $inTransitOut = $this->inTransitQty($p->company_id, $sku, 'out');
            $stock = (float) $p->current_stock;
            $atp = max(0, $stock - $pending);

            return [
                'company_id'          => $p->company_id,
                'company_name'        => $p->company?->name,
                'company_code'        => $p->company?->code,
                'product_id'          => $p->id,
                'sku'                 => $p->sku,
                'product_name'        => $p->name,
                'current_stock'       => $stock,
                'backorder_pending'   => round($pending, 3),
                'in_transit_in'       => round($inTransitIn, 3),
                'in_transit_out'      => round($inTransitOut, 3),
                'available_to_promise'=> round($atp, 3),
                'is_current_branch'   => (int) $p->company_id === (int) $currentCompanyId,
            ];
        })->sortByDesc('is_current_branch')->values()->all();

        return [
            'sku'          => $sku,
            'product_name' => $anchor->name,
            'branches'     => $branches,
        ];
    }

    /** @return list<int|string> */
    private function accessibleCompanyIds(User $user, int|string $currentCompanyId): array
    {
        if ($user->is_super_admin) {
            return \App\Models\Company::active()->pluck('id')->all();
        }

        $ids = $user->companies()->where('is_active', true)->pluck('companies.id')->all();

        return $ids !== [] ? $ids : [$currentCompanyId];
    }

    private function inTransitQty(int|string $companyId, string $sku, string $direction): float
    {
        $query = StockTransfer::query()->where('status', 'in_transit');

        if ($direction === 'in') {
            $query->where('to_company_id', $companyId);
        } else {
            $query->where('company_id', $companyId);
        }

        $transferIds = $query->pluck('id');
        if ($transferIds->isEmpty()) {
            return 0.0;
        }

        return (float) StockTransferItem::query()
            ->whereIn('stock_transfer_id', $transferIds)
            ->whereHas('product', fn ($q) => $q->where('sku', $sku))
            ->get()
            ->sum(fn (StockTransferItem $item) => $item->dispatchQty());
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
