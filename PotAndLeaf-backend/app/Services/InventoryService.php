<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockLedgerEntry;
use Illuminate\Support\Carbon;
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
        private readonly SettingsService $settings,
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
     * Reduce batch remaining quantities, oldest first, by a stock-out that
     * was not aimed at one batch. Stops the batch screen from keeping the
     * original received quantity after the product stock has already fallen.
     */
    /** @return list<array{batch_id: string, batch_no: ?string, qty: float}> what was taken from each batch */
    public function consumeBatchesFifo(string $productId, float $qty): array
    {
        $left = round($qty, 3);
        $taken = [];
        if ($left <= 0) {
            return $taken;
        }

        $batches = ProductBatch::query()
            ->where('product_id', $productId)
            ->where('remaining_qty', '>', 0)
            ->orderBy('received_at')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($left <= 0.0001) {
                break;
            }
            $take = min((float) $batch->remaining_qty, $left);
            $batch->remaining_qty = round((float) $batch->remaining_qty - $take, 3);
            if ((float) $batch->remaining_qty <= 0) {
                $batch->status = 'depleted';
            }
            $batch->save();
            $taken[] = ['batch_id' => $batch->id, 'batch_no' => $batch->batch_no, 'qty' => round($take, 3)];
            $left = round($left - $take, 3);
        }

        return $taken;
    }

    /**
     * Keep the product's opening-stock batch (OPEN-<sku>, with its own barcode)
     * in step with opening stock, so a product added with stock shows a batch
     * and barcode straight away (issue 40). A decrease comes off the oldest
     * batches first.
     */
    public function adjustOpeningBatch(Product $product, float $delta): void
    {
        $delta = round($delta, 3);
        if (abs($delta) < 0.0005) {
            return;
        }
        if ($delta < 0) {
            $this->consumeBatchesFifo($product->id, abs($delta));

            return;
        }

        $batch = ProductBatch::query()
            ->where('product_id', $product->id)
            ->where('batch_no', 'OPEN-'.$product->sku)
            ->lockForUpdate()
            ->first();

        if ($batch) {
            $batch->qty = round((float) $batch->qty + $delta, 3);
            $batch->remaining_qty = round((float) $batch->remaining_qty + $delta, 3);
            $batch->status = 'active';
            $batch->save();

            return;
        }

        ProductBatch::create([
            'company_id'    => $product->company_id,
            'product_id'    => $product->id,
            'batch_no'      => 'OPEN-'.$product->sku,
            'barcode'       => 'PLO'.$product->company_id.'-'.strtoupper(substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 12)),
            'qty'           => $delta,
            'remaining_qty' => $delta,
            'cost_price'    => (float) $product->cost_price,
            'status'        => 'active',
            'received_at'   => now(),
        ]);
    }

    /**
     * Put units back onto batches when stock returns without a known batch
     * (e.g. rented plants coming back). Refills the oldest batches that were
     * drawn down first — the reverse of consumeBatchesFifo — never above the
     * batch's original quantity; anything left goes on the newest batch.
     */
    public function restoreBatchesFifo(string $productId, float $qty): void
    {
        $left = round($qty, 3);
        if ($left <= 0) {
            return;
        }

        $batches = ProductBatch::query()
            ->where('product_id', $productId)
            ->orderBy('received_at')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();
        if ($batches->isEmpty()) {
            return;
        }

        foreach ($batches as $batch) {
            if ($left <= 0.0001) {
                break;
            }
            $room = round((float) $batch->qty - (float) $batch->remaining_qty, 3);
            if ($room <= 0) {
                continue;
            }
            $put = min($room, $left);
            $batch->remaining_qty = round((float) $batch->remaining_qty + $put, 3);
            $batch->status = 'active';
            $batch->save();
            $left = round($left - $put, 3);
        }

        if ($left > 0.0001) {
            $newest = $batches->last();
            $newest->remaining_qty = round((float) $newest->remaining_qty + $left, 3);
            $newest->status = 'active';
            $newest->save();
        }
    }

    /**
     * Bring batch remaining quantities down to physical on-hand stock.
     * Does not rewrite sales, purchases, or other financial documents.
     */
    public function alignBatchRemainingToOnHand(int|string|null $companyId): void
    {
        $products = Product::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->get(['id', 'current_stock']);

        foreach ($products as $product) {
            $batches = ProductBatch::query()
                ->where('product_id', $product->id)
                ->where('remaining_qty', '>', 0)
                ->orderBy('received_at')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $excess = round((float) $batches->sum('remaining_qty') - (float) $product->current_stock, 3);
            if ($excess <= 0.0001) {
                continue;
            }

            foreach ($batches as $batch) {
                if ($excess <= 0.0001) {
                    break;
                }
                $take = min((float) $batch->remaining_qty, $excess);
                $batch->remaining_qty = round((float) $batch->remaining_qty - $take, 3);
                if ((float) $batch->remaining_qty <= 0) {
                    $batch->status = 'depleted';
                }
                $batch->save();
                $excess = round($excess - $take, 3);
            }
        }
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

        $anchor = null;
        if (filled($productId)) {
            $anchor = Product::forCompany($currentCompanyId)->whereKey($productId)->first()
                ?? Product::query()->whereKey($productId)->first();
        } elseif (filled($sku)) {
            $anchor = Product::forCompany($currentCompanyId)->where('sku', $sku)->first()
                ?? Product::query()->where('sku', $sku)->first();
        }

        if (! $anchor) {
            throw ValidationException::withMessages(['sku' => 'Product not found.']);
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
        $ids = \App\Models\Company::active()->pluck('id')->all();

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

        $status = $filters['status'] ?? 'active';

        $page = Product::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->search($filters['search']))
            ->when(($filters['low_only'] ?? false), fn ($q) => $q->whereColumn('current_stock', '<=', 'reorder_level'))
            ->when($status !== 'all', fn ($q) => $q->where('status', in_array($status, ['active', 'inactive'], true) ? $status : 'active'))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $ids = collect($page->items())->pluck('id');
        $reserved = $ids->isEmpty()
            ? collect()
            : \App\Models\StockTransferItem::query()
                ->whereIn('product_id', $ids)
                ->whereHas('stockTransfer', fn ($q) => $q->whereIn('status', ['draft', 'requested']))
                ->get()
                ->groupBy('product_id')
                ->map(fn ($rows) => round($rows->sum(fn ($row) => $row->dispatchQty()), 3));

        foreach ($page->items() as $product) {
            $draftQty = (float) ($reserved[$product->id] ?? 0);
            $product->setAttribute('draft_transfer_qty', $draftQty);
            $product->setAttribute('available_qty', round(max(0, (float) $product->current_stock - $draftQty), 3));
        }

        return $page;
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

    /**
     * Stock valuation at what the stock actually cost (issue 54):
     *   each batch still on the shelf × that batch's landed purchase cost,
     *   plus any stock not held in a batch (opening stock, old data) × product cost price.
     * "cost" is the resulting average cost per unit.
     */
    public function valuation(int|string|null $companyId): array
    {
        $batchTotals = ProductBatch::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('remaining_qty', '>', 0)
            ->selectRaw('product_id, SUM(remaining_qty) as qty, SUM(remaining_qty * cost_price) as value')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $rows = Product::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'current_stock', 'cost_price'])
            ->map(function ($p) use ($batchTotals) {
                $stock = max(0.0, (float) $p->current_stock);
                $batch = $batchTotals[$p->id] ?? null;
                $batchQty = min($stock, (float) ($batch->qty ?? 0));
                $batchValue = $batch && (float) $batch->qty > 0
                    ? (float) $batch->value * $batchQty / (float) $batch->qty
                    : 0.0;
                $value = $batchValue + ($stock - $batchQty) * (float) $p->cost_price;

                return [
                    'id'    => $p->id,
                    'sku'   => $p->sku,
                    'name'  => $p->name,
                    'stock' => (float) $p->current_stock,
                    'cost'  => $stock > 0 ? round($value / $stock, 4) : (float) $p->cost_price,
                    'value' => round($value, 2),
                ];
            });

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
     * Fast / slow / dead classification by outbound movement.
     * Fast/slow use outbound qty in the selected window versus the average of movers.
     * Dead uses company setting dead_stock_days (no outbound in that many days).
     */
    public function movement(int|string|null $companyId, int $days = 30): array
    {
        $days = max(1, min($days, 365));
        $deadDays = $companyId !== null
            ? max(1, $this->settings->getInt($companyId, 'dead_stock_days') ?: 180)
            : 180;
        $since = now()->subDays($days);
        $deadSince = now()->subDays($deadDays);

        $out = StockLedgerEntry::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->where('direction', 'out')
            ->where('occurred_at', '>=', $since)
            ->selectRaw('product_id, SUM(qty) as out_qty, MAX(occurred_at) as last_out')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $lastOut = StockLedgerEntry::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->where('direction', 'out')
            ->selectRaw('product_id, MAX(occurred_at) as last_out')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $movers = $out->where('out_qty', '>', 0);
        $avg = $movers->count() ? (float) $movers->avg('out_qty') : 0.0;

        $rows = Product::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'current_stock'])
            ->map(function ($p) use ($out, $avg, $lastOut, $deadSince) {
                $outQty = (float) ($out[$p->id]->out_qty ?? 0);
                $last = $lastOut[$p->id]->last_out ?? $out[$p->id]->last_out ?? null;
                $isDead = $last === null || Carbon::parse($last)->lt($deadSince);
                $class = $isDead ? 'dead' : ($outQty >= $avg && $outQty > 0 ? 'fast' : 'slow');

                return [
                    'id'       => $p->id,
                    'sku'      => $p->sku,
                    'name'     => $p->name,
                    'stock'    => (float) $p->current_stock,
                    'out_qty'  => round($outQty, 3),
                    'last_out' => $last,
                    'class'    => $class,
                ];
            });

        return [
            'days'            => $days,
            'dead_stock_days' => $deadDays,
            'method'          => 'Fast/slow: outbound qty in the selected window vs average of movers. Dead: no outbound movement in dead_stock_days.',
            'items'           => $rows->values(),
            'summary'         => [
                'fast' => $rows->where('class', 'fast')->count(),
                'slow' => $rows->where('class', 'slow')->count(),
                'dead' => $rows->where('class', 'dead')->count(),
            ],
        ];
    }
}
