<?php

namespace App\Services;

use App\Actions\Purchases\CreatePurchase;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(private readonly CreatePurchase $createPurchase) {}

    /** Products at or below reorder level, with a suggested top-up qty and preferred supplier. */
    public function reorderSuggestions(int|string $companyId): array
    {
        $products = Product::forCompany($companyId)
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->where('reorder_level', '>', 0)
            ->with([
                'category:id,name',
                'unit:id,name,short_name',
                'suppliers' => fn ($q) => $q->orderByDesc('product_supplier.is_primary'),
            ])
            ->orderBy('name')
            ->get();

        $lastRates = $this->lastPurchaseRates($companyId, $products->pluck('id')->all());
        $onOrder = $this->openOrderQty($companyId, $products->pluck('id')->all());

        // Stock already on an open (draft/sent) PO counts as coming in, so a
        // product is not suggested again once enough is on order (issue 3).
        $products = $products->filter(fn (Product $p) => (float) $p->current_stock + (float) ($onOrder[$p->id]['qty'] ?? 0) <= (float) $p->reorder_level)->values();

        return $products->map(function (Product $p) use ($lastRates, $onOrder) {
            $ordered = (float) ($onOrder[$p->id]['qty'] ?? 0);
            $target = (float) $p->reorder_level * 2;
            $suggested = max(0.0, round($target - (float) $p->current_stock - $ordered, 3));
            $shortfall = max(0.0, round((float) $p->reorder_level - (float) $p->current_stock, 3));
            $primary = $p->suppliers->first();
            $lastPrice = isset($lastRates[$p->id]) ? (float) $lastRates[$p->id] : null;
            $supplierPrice = $primary?->pivot?->supplier_price !== null
                ? (float) $primary->pivot->supplier_price
                : null;
            $rate = $this->preferredRate($supplierPrice, $lastPrice, (float) $p->cost_price);

            $suppliers = $p->suppliers->map(fn ($s) => [
                'id'             => $s->id,
                'name'           => $s->name,
                'is_primary'     => (bool) $s->pivot->is_primary,
                'supplier_price' => $s->pivot->supplier_price !== null ? (float) $s->pivot->supplier_price : null,
            ])->values()->all();

            return [
                'product_id'          => $p->id,
                'name'                => $p->name,
                'sku'                 => $p->sku,
                'category'            => $p->category?->name,
                'unit'                => $p->unit?->short_name ?: $p->unit?->name,
                'current_stock'       => (float) $p->current_stock,
                'on_order'            => $ordered,
                'open_po_nos'         => $onOrder[$p->id]['po_nos'] ?? [],
                'reorder_level'       => (float) $p->reorder_level,
                'required_qty'        => $shortfall,
                'shortfall'           => $shortfall,
                'suggested_qty'       => $suggested > 0 ? $suggested : (float) $p->reorder_level,
                'last_purchase_price' => $lastPrice,
                'supplier_price'      => $supplierPrice,
                'rate'                => $rate,
                'gst_rate'            => (float) $p->gst_rate,
                'supplier_id'         => $primary?->id,
                'supplier_name'       => $primary?->name,
                'preferred_supplier'  => $primary?->name,
                'suppliers'           => $suppliers,
            ];
        })->all();
    }

    /**
     * Quantity still to arrive on open purchase orders (draft or sent), per product.
     *
     * @return array<string, array{qty: float, po_nos: list<string>}>
     */
    public function openOrderQty(int|string $companyId, array $productIds, ?string $exceptOrderId = null): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.company_id', $companyId)
            ->whereIn('purchase_orders.status', ['draft', 'sent'])
            ->whereNull('purchase_orders.deleted_at')
            ->when($exceptOrderId, fn ($q) => $q->where('purchase_orders.id', '!=', $exceptOrderId))
            ->whereIn('purchase_order_items.product_id', $productIds)
            ->get(['purchase_order_items.product_id', 'purchase_order_items.qty', 'purchase_orders.po_no'])
            ->groupBy('product_id')
            ->map(fn ($rows) => [
                'qty'    => round((float) $rows->sum('qty'), 3),
                'po_nos' => $rows->pluck('po_no')->unique()->values()->all(),
            ])
            ->all();
    }

    /** Latest confirmed purchase rate per product. */
    private function lastPurchaseRates(int|string $companyId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return PurchaseItem::query()
            ->select('purchase_items.product_id', 'purchase_items.rate')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.company_id', $companyId)
            ->where('purchases.status', 'confirmed')
            ->whereNull('purchases.deleted_at')
            ->whereIn('purchase_items.product_id', $productIds)
            ->orderByDesc('purchases.purchase_date')
            ->orderByDesc('purchase_items.id')
            ->get()
            ->unique('product_id')
            ->pluck('rate', 'product_id')
            ->all();
    }

    private function preferredRate(?float $supplierPrice, ?float $lastPrice, float $costPrice): float
    {
        foreach ([$supplierPrice, $lastPrice, $costPrice] as $value) {
            if ($value !== null && $value > 0) {
                return round((float) $value, 2);
            }
        }

        return 0.0;
    }

    /** Reorder report grouped by preferred supplier for batch PO generation. */
    public function reorderReport(int|string $companyId): array
    {
        $lines = $this->reorderSuggestions($companyId);
        $groups = collect($lines)->groupBy(fn ($row) => $row['supplier_id'] ?? 'unassigned');

        $suppliers = [];
        $unassigned = [];

        foreach ($groups as $key => $items) {
            $rows = $items->values()->all();
            $subtotal = collect($rows)->sum(fn ($r) => (float) $r['suggested_qty'] * (float) $r['rate']);

            if ($key === 'unassigned') {
                $unassigned = $rows;
                continue;
            }

            $suppliers[] = [
                'supplier_id'   => $key,
                'supplier_name' => $rows[0]['supplier_name'] ?? 'Supplier',
                'items'         => $rows,
                'item_count'    => count($rows),
                'estimated_value' => round($subtotal, 2),
            ];
        }

        usort($suppliers, fn ($a, $b) => strcmp($a['supplier_name'], $b['supplier_name']));

        return [
            'summary' => [
                'product_count'   => count($lines),
                'supplier_count'  => count($suppliers),
                'unassigned_count'=> count($unassigned),
                'estimated_value' => round(collect($lines)->sum(fn ($r) => (float) $r['suggested_qty'] * (float) $r['rate']), 2),
            ],
            'suppliers'  => $suppliers,
            'unassigned' => $unassigned,
        ];
    }

    /**
     * Create one draft PO per supplier from a reviewed reorder batch.
     *
     * @param  array{po_date: string, expected_date?: string, notes?: string, orders: list<array{supplier_id: string, items: list}>}  $data
     * @return list<PurchaseOrder>
     */
    public function createBatchFromReorder(int|string $companyId, array $data, ?int $userId = null): array
    {
        $created = [];

        return DB::transaction(function () use ($companyId, $data, $userId, &$created) {
            foreach ($data['orders'] as $order) {
                $items = array_values(array_filter($order['items'] ?? [], fn ($i) => (float) ($i['qty'] ?? 0) > 0));
                if ($items === []) {
                    continue;
                }

                $created[] = $this->create($companyId, [
                    'supplier_id'   => $order['supplier_id'],
                    'po_date'       => $data['po_date'],
                    'expected_date' => $data['expected_date'] ?? null,
                    'notes'         => filled($data['notes'] ?? null)
                        ? trim($data['notes']).' — Auto-generated from reorder report'
                        : 'Auto-generated from reorder report',
                    'items'         => $items,
                    // The reorder report already subtracts what is on order and shows it.
                    'allow_duplicate' => true,
                ], $userId);
            }

            if ($created === []) {
                throw ValidationException::withMessages(['orders' => 'No purchase orders to create — add at least one line with quantity.']);
            }

            return $created;
        });
    }

    public function list(int|string|null $companyId, array $filters): LengthAwarePaginator
    {
        return PurchaseOrder::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->with('supplier:id,name')
            ->withCount('items')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('po_no', 'like', "%{$filters['search']}%"))
            ->orderByDesc('po_date')->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 15), 100))
            ->withQueryString();
    }

    public function find(int|string $companyId, string $id): ?PurchaseOrder
    {
        return PurchaseOrder::forCompany($companyId)
            ->with(['items.product:id,sku,name', 'supplier', 'company', 'createdBy:id,name'])
            ->whereKey($id)
            ->first();
    }

    public function create(int|string $companyId, array $data, ?int $userId = null): PurchaseOrder
    {
        $names = Product::forCompany($companyId)
            ->whereIn('id', collect($data['items'])->pluck('product_id')->filter())
            ->pluck('name', 'id');

        // Issue 3: one line per product, and warn when it is already on an open PO.
        $ids = collect($data['items'])->pluck('product_id');
        $dupes = $ids->duplicates()->unique();
        if ($dupes->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => $dupes->map(fn ($id) => ($names[$id] ?? 'An item').' is added more than once. Put the total quantity on one line.')->values()->all(),
            ]);
        }
        if (empty($data['allow_duplicate'])) {
            $open = $this->openOrderQty($companyId, $ids->unique()->values()->all());
            if ($open !== []) {
                throw ValidationException::withMessages([
                    'open_orders' => collect($open)->map(fn ($o, $id) => ($names[$id] ?? 'An item').' is already on '.implode(', ', $o['po_nos']).' ('.rtrim(rtrim(number_format($o['qty'], 3, '.', ''), '0'), '.').' still to come).')->values()->all(),
                ]);
            }
        }

        $rows = [];
        $subtotal = 0.0;
        $taxTotal = 0.0;
        foreach ($data['items'] as $item) {
            $qty = (float) $item['qty'];
            $rate = (float) $item['rate'];
            $gstRate = (float) ($item['gst_rate'] ?? 0);
            $taxable = round($qty * $rate, 2);
            $tax = round($taxable * $gstRate / 100, 2);
            $subtotal += $taxable;
            $taxTotal += $tax;
            $rows[] = [
                'product_id'    => $item['product_id'],
                'product_name'  => $names[$item['product_id']] ?? 'Item',
                'qty'           => $qty,
                'rate'          => $rate,
                'gst_rate'      => $gstRate,
                'taxable_value' => $taxable,
                'line_total'    => round($taxable + $tax, 2),
            ];
        }

        return DB::transaction(function () use ($companyId, $data, $rows, $subtotal, $taxTotal) {
            $po = PurchaseOrder::create([
                'company_id'    => $companyId,
                'supplier_id'   => $data['supplier_id'],
                'po_no'         => $this->nextPoNo($companyId),
                'po_date'       => $data['po_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'status'        => 'draft',
                'subtotal'      => round($subtotal, 2),
                'tax_total'     => round($taxTotal, 2),
                'grand_total'   => round($subtotal + $taxTotal, 2),
                'notes'         => $data['notes'] ?? null,
            ]);
            $po->items()->createMany($rows);

            return $po->load(['items', 'supplier:id,name']);
        });
    }

    public function send(PurchaseOrder $po): PurchaseOrder
    {
        if (! $po->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only draft POs can be marked as sent.']);
        }
        $po->update(['status' => 'sent']);

        return $po->refresh()->load(['items', 'supplier:id,name']);
    }

    public function cancel(PurchaseOrder $po): PurchaseOrder
    {
        if (! $po->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Only open POs can be cancelled.']);
        }
        $po->update(['status' => 'cancelled']);

        return $po->refresh();
    }

    /** Turn an open PO into a draft purchase (GRN) ready to receive. */
    public function convertToPurchase(PurchaseOrder $po, ?int $userId = null): array
    {
        return DB::transaction(function () use ($po, $userId) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->first();
            if (! $po || ! $po->isOpen() || $po->purchase_id) {
                throw ValidationException::withMessages(['status' => 'This PO has already been received or cancelled.']);
            }
            $po->load('items');
            $purchase = $this->createPurchase->handle($po->company_id, [
                'supplier_id'   => $po->supplier_id,
                'purchase_date' => now()->toDateString(),
                'is_interstate' => false,
                'items' => $po->items->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'qty'        => (float) $i->qty,
                    'rate'       => (float) $i->rate,
                    'gst_rate'   => (float) $i->gst_rate,
                ])->all(),
                'notes' => "From PO {$po->po_no}",
            ], $userId);

            $po->update(['status' => 'received', 'purchase_id' => $purchase->id]);

            return ['purchase_id' => $purchase->id, 'po' => $po->refresh()];
        });
    }

    private function nextPoNo(int|string $companyId): string
    {
        $count = PurchaseOrder::withTrashed()->forCompany($companyId)->count();

        return 'PO-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
