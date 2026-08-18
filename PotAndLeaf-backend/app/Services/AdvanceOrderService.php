<?php

namespace App\Services;

use App\Actions\Sales\CreateSale;
use App\Models\AdvanceOrder;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdvanceOrderService
{
    public function __construct(private readonly CreateSale $createSale) {}

    public function list(int|string $companyId, array $filters): LengthAwarePaginator
    {
        return AdvanceOrder::forCompany($companyId)
            ->with('customer:id,name')
            ->withCount('items')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('order_no', 'like', "%{$filters['search']}%"))
            ->orderByDesc('order_date')->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 15), 100))
            ->withQueryString();
    }

    public function find(int|string $companyId, string $id): ?AdvanceOrder
    {
        return AdvanceOrder::forCompany($companyId)->with(['items', 'customer:id,name,type'])->whereKey($id)->first();
    }

    public function create(int|string $companyId, array $data, ?int $userId = null): AdvanceOrder
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
            $order = AdvanceOrder::create([
                'company_id'     => $companyId,
                'customer_id'    => $data['customer_id'],
                'order_no'       => $this->nextOrderNo($companyId),
                'order_date'     => $data['order_date'],
                'expected_date'  => $data['expected_date'] ?? null,
                'status'         => 'booked',
                'advance_amount' => $data['advance_amount'] ?? 0,
                'subtotal'       => round($subtotal, 2),
                'tax_total'      => round($taxTotal, 2),
                'grand_total'    => round($subtotal + $taxTotal, 2),
                'notes'          => $data['notes'] ?? null,
            ]);
            $order->items()->createMany($rows);

            return $order->load(['items', 'customer:id,name,type']);
        });
    }

    public function cancel(AdvanceOrder $order): AdvanceOrder
    {
        if (! $order->isBooked()) {
            throw ValidationException::withMessages(['status' => 'Only booked orders can be cancelled.']);
        }
        $order->update(['status' => 'cancelled']);

        return $order->refresh();
    }

    /** Fulfil: create a draft credit sale from the booking (advance counted as paid). */
    public function fulfill(AdvanceOrder $order, ?int $userId = null): array
    {
        if (! $order->isBooked()) {
            throw ValidationException::withMessages(['status' => 'This order has already been fulfilled or cancelled.']);
        }

        return DB::transaction(function () use ($order, $userId) {
            $order->loadMissing('items');
            $sale = $this->createSale->handle($order->company_id, [
                'customer_id'  => $order->customer_id,
                'sale_date'    => now()->toDateString(),
                'is_interstate' => false,
                'payment_mode' => 'credit',
                'amount_paid'  => (float) $order->advance_amount,
                'items' => $order->items->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'qty'        => (float) $i->qty,
                    'rate'       => (float) $i->rate,
                    'gst_rate'   => (float) $i->gst_rate,
                ])->all(),
                'notes' => "From advance order {$order->order_no}",
            ], $userId);

            $order->update(['status' => 'fulfilled', 'sale_id' => $sale->id]);

            return ['sale_id' => $sale->id, 'order' => $order->refresh()];
        });
    }

    private function nextOrderNo(int|string $companyId): string
    {
        $count = AdvanceOrder::withTrashed()->forCompany($companyId)->count();

        return 'AO-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
