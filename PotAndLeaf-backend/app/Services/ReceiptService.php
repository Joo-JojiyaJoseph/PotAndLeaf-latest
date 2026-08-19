<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\Sale;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ReceiptService
{
    public function list(int|string|null $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return CustomerReceipt::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->with(['customer:id,name', 'sale:id,sale_no'])
            ->when(filled($filters['customer_id'] ?? null), fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->orderByDesc('receipt_date')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function record(int|string $companyId, array $data, ?int $userId = null): CustomerReceipt
    {
        return DB::transaction(function () use ($companyId, $data) {
            $receipt = CustomerReceipt::create([
                'company_id'   => $companyId,
                'customer_id'  => $data['customer_id'],
                'sale_id'      => $data['sale_id'] ?? null,
                'receipt_no'   => $this->nextReceiptNo($companyId),
                'receipt_date' => $data['receipt_date'],
                'amount'       => $data['amount'],
                'mode'         => $data['mode'] ?? 'cash',
                'reference'    => $data['reference'] ?? null,
                'notes'        => $data['notes'] ?? null,
            ]);

            $customer = Customer::forCompany($companyId)->lockForUpdate()->find($data['customer_id']);
            if ($customer) {
                $customer->outstanding = (float) $customer->outstanding - (float) $data['amount'];
                $customer->save();
            }

            if (! empty($data['sale_id'])) {
                $this->syncSalePaid($data['sale_id']);
            }

            return $receipt->load(['customer:id,name', 'sale:id,sale_no']);
        });
    }

    /** Advance collected on booking — increases advance_balance, not AR. */
    public function recordAdvance(int|string $companyId, array $data, ?string $advanceOrderId = null, ?int $userId = null): CustomerReceipt
    {
        return DB::transaction(function () use ($companyId, $data, $advanceOrderId, $userId) {
            $receipt = CustomerReceipt::create([
                'company_id'       => $companyId,
                'customer_id'      => $data['customer_id'],
                'advance_order_id' => $advanceOrderId,
                'receipt_no'       => $this->nextReceiptNo($companyId),
                'receipt_date'     => $data['receipt_date'],
                'amount'           => $data['amount'],
                'mode'             => $data['mode'] ?? 'cash',
                'reference'        => $data['reference'] ?? null,
                'notes'            => $data['notes'] ?? 'Advance on booking',
                'created_by'       => $userId,
            ]);

            $customer = Customer::forCompany($companyId)->lockForUpdate()->find($data['customer_id']);
            if ($customer) {
                $customer->advance_balance = (float) $customer->advance_balance + (float) $data['amount'];
                $customer->save();
            }

            return $receipt->load(['customer:id,name']);
        });
    }

    public function voidAdvanceOrder(string $advanceOrderId): void
    {
        $receipts = CustomerReceipt::query()->where('advance_order_id', $advanceOrderId)->get();
        foreach ($receipts as $receipt) {
            DB::transaction(function () use ($receipt) {
                $customer = Customer::forCompany($receipt->company_id)->lockForUpdate()->find($receipt->customer_id);
                if ($customer) {
                    $customer->advance_balance = max(0, (float) $customer->advance_balance - (float) $receipt->amount);
                    $customer->save();
                }
                $receipt->delete();
            });
        }
    }

    /** POS confirm: record tender without adjusting outstanding (cash/upi/card sales never raised AR). */
    public function recordFromConfirmedSale(Sale $sale, ?int $userId = null): ?CustomerReceipt
    {
        if (! $sale->customer_id || (float) $sale->amount_paid <= 0) {
            return null;
        }

        if ($sale->payment_mode === 'credit' && (float) $sale->amount_paid <= 0) {
            return null;
        }

        if (CustomerReceipt::where('sale_id', $sale->id)->exists()) {
            return null;
        }

        $mode = in_array($sale->payment_mode, ['cash', 'card', 'upi', 'cheque', 'bank'], true)
            ? $sale->payment_mode
            : 'cash';

        return CustomerReceipt::create([
            'company_id'   => $sale->company_id,
            'customer_id'  => $sale->customer_id,
            'sale_id'      => $sale->id,
            'receipt_no'   => $this->nextReceiptNo($sale->company_id),
            'receipt_date' => $sale->sale_date?->toDateString() ?? now()->toDateString(),
            'amount'       => $sale->amount_paid,
            'mode'         => $mode,
            'notes'        => "Receipt for sale {$sale->sale_no}",
            'created_by'   => $userId,
        ]);
    }

    /** Remove auto receipts from a cancelled sale without reversing outstanding (none was adjusted). */
    public function voidForSale(Sale $sale): void
    {
        CustomerReceipt::query()->where('sale_id', $sale->id)->get()->each->delete();
    }

    public function delete(CustomerReceipt $receipt): void
    {
        DB::transaction(function () use ($receipt) {
            $customer = Customer::forCompany($receipt->company_id)->lockForUpdate()->find($receipt->customer_id);
            if ($customer) {
                $customer->outstanding = (float) $customer->outstanding + (float) $receipt->amount;
                $customer->save();
            }
            $saleId = $receipt->sale_id;
            $receipt->delete();
            if ($saleId) {
                $this->syncSalePaid($saleId);
            }
        });
    }

    /** Confirmed sales that carry a credit balance, with received / balance / status. */
    public function receivables(int|string|null $companyId, ?string $customerId = null): array
    {
        return Sale::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->where('status', 'confirmed')
            ->whereNotNull('customer_id')
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->with('customer:id,name,credit_days')
            ->withSum('customerReceipts as received_sum', 'amount')
            ->orderByDesc('sale_date')
            ->get()
            ->map(function (Sale $s) {
                $invoice = round(max(0, (float) $s->grand_total - (float) $s->loyalty_discount), 2);
                $received = (float) ($s->received_sum ?? 0);
                // Legacy sales recorded tender only on amount_paid (no receipt row).
                if ($received <= 0.005) {
                    $received = (float) $s->amount_paid;
                }
                $balance = round($invoice - $received, 2);
                $due = $s->sale_date?->copy()->addDays((int) ($s->customer->credit_days ?? 0));

                return [
                    'id'            => $s->id,
                    'sale_no'       => $s->sale_no,
                    'customer_id'   => $s->customer_id,
                    'customer_name' => $s->customer?->name,
                    'invoice_total' => $invoice,
                    'credit_amount' => $invoice,
                    'received'      => round($received, 2),
                    'balance'       => $balance,
                    'status'        => $balance <= 0.005 ? 'paid' : ($received <= 0.005 ? 'unpaid' : 'partial'),
                    'due_date'      => $due?->toDateString(),
                ];
            })
            ->filter(fn ($r) => $r['balance'] > 0.005)
            ->values()
            ->all();
    }

    private function nextReceiptNo(int|string $companyId): string
    {
        $count = CustomerReceipt::withTrashed()->forCompany($companyId)->count();

        return 'RCP-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    private function syncSalePaid(string $saleId): void
    {
        $sale = Sale::lockForUpdate()->find($saleId);
        if (! $sale) {
            return;
        }

        $paid = (float) CustomerReceipt::query()
            ->where('sale_id', $saleId)
            ->sum('amount');

        $sale->amount_paid = round($paid, 2);
        $sale->save();
    }
}
