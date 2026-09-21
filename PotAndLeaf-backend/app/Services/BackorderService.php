<?php

namespace App\Services;

use App\Actions\Sales\CreateSale;
use App\Models\Backorder;
use App\Models\BackorderItem;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BackorderService
{
    public function __construct(
        private readonly CreateSale $createSale,
        private readonly ShortageResolutionService $shortage,
    ) {}

    public function list(int|string|null $companyId, array $filters): LengthAwarePaginator
    {
        return Backorder::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->with('customer:id,name')
            ->withCount('items')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('order_no', 'like', "%{$filters['search']}%"))
            ->orderByDesc('order_date')->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 15), 100))
            ->withQueryString();
    }

    public function find(int|string $companyId, string $id): ?Backorder
    {
        return Backorder::forCompany($companyId)
            ->with(['items.product:id,sku,current_stock', 'customer:id,name,type'])
            ->whereKey($id)
            ->first();
    }

    /**
     * Request unavailable qty: create inter-company transfer requests where
     * another branch has stock; only leftover qty becomes a backorder.
     *
     * @param  array<string,mixed>  $data
     * @return array{backorder: ?Backorder, transfers: list<\App\Models\StockTransfer>, resolution: list<array<string,mixed>>}
     */
    public function requestShortage(int|string $companyId, array $data, ?int $userId = null): array
    {
        foreach ($data['items'] as $i => $item) {
            if ((float) $item['ordered_qty'] <= 0) {
                throw ValidationException::withMessages(["items.{$i}.ordered_qty" => 'Quantity must be greater than zero.']);
            }
        }

        return DB::transaction(function () use ($companyId, $data, $userId) {
            $resolved = $this->shortage->allocate($companyId, $data['items'], $userId, [
                'sale_no' => $data['sale_no'] ?? null,
                'notes'   => $data['notes'] ?? null,
            ]);

            if ($resolved['transfers'] === [] && $resolved['backorderItems'] === []) {
                throw ValidationException::withMessages([
                    'items' => 'Enter a quantity greater than zero for at least one product.',
                ]);
            }

            $backorder = null;
            if ($resolved['backorderItems'] !== []) {
                $persist = $data;
                $persist['items'] = $resolved['backorderItems'];
                $backorder = $this->persist($companyId, $persist);
            }

            return [
                'backorder'  => $backorder,
                'transfers'  => $resolved['transfers'],
                'resolution' => $resolved['resolution'],
            ];
        });
    }

    /** @param array<string,mixed> $data */
    public function create(int|string $companyId, array $data, ?int $userId = null): Backorder
    {
        foreach ($data['items'] as $i => $item) {
            if ((float) $item['ordered_qty'] <= 0) {
                throw ValidationException::withMessages(["items.{$i}.ordered_qty" => 'Quantity must be greater than zero.']);
            }
        }

        return DB::transaction(fn () => $this->persist($companyId, $data));
    }

    /** @param array<string,mixed> $data */
    private function persist(int|string $companyId, array $data): Backorder
    {
        $names = Product::forCompany($companyId)
            ->whereIn('id', collect($data['items'])->pluck('product_id')->filter())
            ->pluck('name', 'id');

        $rows = [];
        foreach ($data['items'] as $item) {
            $rows[] = [
                'product_id'    => $item['product_id'],
                'sale_item_id'  => $item['sale_item_id'] ?? null,
                'product_name'  => $item['product_name'] ?? ($names[$item['product_id']] ?? 'Item'),
                'ordered_qty'   => (float) $item['ordered_qty'],
                'fulfilled_qty' => 0,
                'cancelled_qty' => 0,
                'rate'          => (float) ($item['rate'] ?? 0),
            ];
        }

        $order = Backorder::create([
            'company_id'    => $companyId,
            'customer_id'   => $data['customer_id'],
            'location_id'   => $data['location_id'] ?? null,
            'sale_id'       => $data['sale_id'] ?? null,
            'order_no'      => $this->nextOrderNo($companyId),
            'order_date'    => $data['order_date'],
            'expected_date' => $data['expected_date'] ?? null,
            'status'        => 'open',
            'notes'         => $data['notes'] ?? null,
        ]);
        $order->items()->createMany($rows);

        return $order->load(['items', 'customer:id,name,type']);
    }

    /**
     * Resolve shortage on a draft sale: transfer from other branches when
     * stock exists there, otherwise backorder the remainder.
     *
     * @return array{backorder: ?Backorder, transfers: list<\App\Models\StockTransfer>, resolution: list<array<string,mixed>>}
     */
    public function createFromSale(Sale $sale, ?int $userId = null): array
    {
        if (! $sale->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Backorders can only be created from draft sales.']);
        }

        if (! $sale->customer_id) {
            throw ValidationException::withMessages(['customer_id' => 'Select a customer before creating a backorder.']);
        }

        $sale->loadMissing('items');
        $products = Product::forCompany($sale->company_id)
            ->whereIn('id', $sale->items->pluck('product_id'))
            ->get(['id', 'name', 'current_stock'])
            ->keyBy('id');

        $shortageRows = [];
        foreach ($sale->items as $item) {
            $product = $products[$item->product_id] ?? null;
            if (! $product) {
                continue;
            }
            $available = (float) $product->current_stock;
            $needed = (float) $item->qty;
            $short = $needed - $available;
            if ($short <= 0) {
                continue;
            }
            $shortageRows[] = [
                'product_id'   => $item->product_id,
                'sale_item_id' => $item->id,
                'product_name' => $item->product_name,
                'ordered_qty'  => $short,
                'rate'         => (float) $item->rate,
            ];
        }

        if ($shortageRows === []) {
            throw ValidationException::withMessages(['items' => 'All line items have sufficient stock — no backorder needed.']);
        }

        return $this->requestShortage($sale->company_id, [
            'customer_id' => $sale->customer_id,
            'location_id' => $sale->location_id,
            'sale_id'     => $sale->id,
            'sale_no'     => $sale->sale_no,
            'order_date'  => now()->toDateString(),
            'notes'       => "Shortage from draft sale {$sale->sale_no}",
            'items'       => $shortageRows,
        ], $userId);
    }

    public function cancel(Backorder $order): Backorder
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Only open or partially fulfilled backorders can be cancelled.']);
        }

        return DB::transaction(function () use ($order) {
            $order->loadMissing('items');
            foreach ($order->items as $item) {
                $pending = $item->pendingQty();
                if ($pending > 0) {
                    $item->update([
                        'cancelled_qty' => (float) $item->cancelled_qty + $pending,
                    ]);
                }
            }
            $order->update(['status' => 'cancelled']);

            return $order->refresh()->load(['items', 'customer:id,name']);
        });
    }

    /**
     * Partial or full fulfillment — creates a draft sale for the fulfilled qty.
     *
     * @param  array<int, array{id: string, qty: float}>  $lines
     * @return array{sale_id: string, order: Backorder}
     */
    public function fulfill(Backorder $order, array $lines, ?int $userId = null): array
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages(['status' => 'This backorder is already closed.']);
        }

        return DB::transaction(function () use ($order, $lines, $userId) {
            $order->loadMissing('items');
            $itemsById = $order->items->keyBy('id');
            $saleLines = [];

            foreach ($lines as $i => $line) {
                $item = $itemsById[$line['id'] ?? ''] ?? null;
                if (! $item) {
                    throw ValidationException::withMessages(["items.{$i}.id" => 'Invalid backorder line.']);
                }
                $qty = (float) ($line['qty'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                if ($qty > $item->pendingQty() + 0.0001) {
                    throw ValidationException::withMessages([
                        "items.{$i}.qty" => "Cannot fulfill more than pending qty ({$item->pendingQty()}) for {$item->product_name}.",
                    ]);
                }

                $product = Product::forCompany($order->company_id)->find($item->product_id);
                if (! $product || (float) $product->current_stock < $qty) {
                    throw ValidationException::withMessages([
                        "items.{$i}.qty" => "Insufficient stock for {$item->product_name}.",
                    ]);
                }

                $saleLines[] = [
                    'product_id' => $item->product_id,
                    'qty'        => $qty,
                    'rate'       => (float) $item->rate,
                    'gst_rate'   => (float) ($product->gst_rate ?? 0),
                ];
                $item->update(['fulfilled_qty' => (float) $item->fulfilled_qty + $qty]);
            }

            if ($saleLines === []) {
                throw ValidationException::withMessages(['items' => 'Specify at least one quantity to fulfill.']);
            }

            $sale = $this->createSale->handle($order->company_id, [
                'customer_id'   => $order->customer_id,
                'location_id'   => $order->location_id,
                'sale_date'     => now()->toDateString(),
                'is_interstate' => false,
                'payment_mode'  => 'credit',
                'amount_paid'   => 0,
                'items'         => $saleLines,
                'notes'         => "Fulfillment of backorder {$order->order_no}",
            ], $userId);

            $this->refreshStatus($order);

            return ['sale_id' => $sale->id, 'order' => $order->refresh()->load(['items', 'customer:id,name'])];
        });
    }

    /** Sum pending backorder qty for a product at a company (open + partial orders). */
    public function pendingQtyForProduct(int|string $companyId, string $productId): float
    {
        return (float) BackorderItem::query()
            ->whereHas('backorder', fn ($q) => $q->forCompany($companyId)->whereIn('status', ['open', 'partial']))
            ->where('product_id', $productId)
            ->selectRaw('SUM(ordered_qty - fulfilled_qty - cancelled_qty) as pending')
            ->value('pending') ?: 0.0;
    }

    private function refreshStatus(Backorder $order): void
    {
        $order->loadMissing('items');
        $pending = $order->items->sum(fn (BackorderItem $i) => $i->pendingQty());
        $fulfilled = $order->items->sum(fn (BackorderItem $i) => (float) $i->fulfilled_qty);

        $status = match (true) {
            $pending <= 0 => 'fulfilled',
            $fulfilled > 0 => 'partial',
            default => 'open',
        };

        $order->update(['status' => $status]);
    }

    private function nextOrderNo(int|string $companyId): string
    {
        $count = Backorder::withTrashed()->forCompany($companyId)->count();

        return 'BO-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
