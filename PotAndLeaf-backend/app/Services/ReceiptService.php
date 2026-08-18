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

            return $receipt->load(['customer:id,name', 'sale:id,sale_no']);
        });
    }

    public function delete(CustomerReceipt $receipt): void
    {
        DB::transaction(function () use ($receipt) {
            $customer = Customer::forCompany($receipt->company_id)->lockForUpdate()->find($receipt->customer_id);
            if ($customer) {
                $customer->outstanding = (float) $customer->outstanding + (float) $receipt->amount;
                $customer->save();
            }
            $receipt->delete();
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
}
