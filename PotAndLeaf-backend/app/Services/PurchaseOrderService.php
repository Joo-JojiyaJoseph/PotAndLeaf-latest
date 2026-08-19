<?php

namespace App\Services;

use App\Actions\Purchases\CreatePurchase;
use App\Models\Product;
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
        return Product::forCompany($companyId)
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->where('reorder_level', '>', 0)
            ->with(['suppliers' => fn ($q) => $q->orderByDesc('product_supplier.is_primary')])
            ->orderBy('name')
            ->get()
            ->map(function (Product $p) {
                $target = (float) $p->reorder_level * 2;
                $suggested = max(0.0, round($target - (float) $p->current_stock, 3));
                $primary = $p->suppliers->first();

                return [
                    'product_id'    => $p->id,
                    'name'          => $p->name,
                    'sku'           => $p->sku,
                    'current_stock' => (float) $p->current_stock,
                    'reorder_level' => (float) $p->reorder_level,
                    'shortfall'     => max(0.0, round((float) $p->reorder_level - (float) $p->current_stock, 3)),
                    'suggested_qty' => $suggested > 0 ? $suggested : (float) $p->reorder_level,
                    'rate'          => (float) $p->cost_price,
                    'gst_rate'      => (float) $p->gst_rate,
                    'supplier_id'   => $primary?->id,
                    'supplier_name' => $primary?->name,
                ];
            })
            ->all();
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
        return PurchaseOrder::forCompany($companyId)->with(['items', 'supplier:id,name'])->whereKey($id)->first();
    }

    public function create(int|string $companyId, array $data, ?int $userId = null): PurchaseOrder
    {
        $names = Product::forCompany($companyId)
            ->whereIn('id', collect($data['items'])->pluck('product_id')->filter())
            ->pluck('name', 'id');

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
        if (! $po->isOpen()) {
            throw ValidationException::withMessages(['status' => 'This PO has already been received or cancelled.']);
        }

        return DB::transaction(function () use ($po, $userId) {
            $po->loadMissing('items');
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
