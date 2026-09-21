<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\Sale;
use App\Services\AccountingEngine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ReceiptService
{
    public function list(int|string|null $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return CustomerReceipt::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->with(['customer:id,name', 'sale:id,sale_no', 'allocations.sale:id,sale_no'])
            ->when(filled($filters['customer_id'] ?? null), fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->orderByDesc('receipt_date')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function record(int|string $companyId, array $data, ?int $userId = null): CustomerReceipt
    {
        $receipt = DB::transaction(function () use ($companyId, $data) {
            $isAdvance = (bool) ($data['is_advance'] ?? false);
            $allocations = array_values(array_filter(
                $data['allocations'] ?? [],
                fn ($row) => (float) ($row['amount'] ?? 0) > 0 && filled($row['sale_id'] ?? null),
            ));

            if ($isAdvance && ($allocations !== [] || filled($data['sale_id'] ?? null))) {
                throw ValidationException::withMessages([
                    'is_advance' => 'An advance cannot be allocated to an invoice in the same step. Record the advance, then apply it.',
                ]);
            }

            $receipt = CustomerReceipt::create([
                'company_id'   => $companyId,
                'customer_id'  => $data['customer_id'],
                'sale_id'      => $allocations === [] ? ($data['sale_id'] ?? null) : null,
                'receipt_no'   => $this->nextReceiptNo($companyId),
                'receipt_date' => $data['receipt_date'],
                'amount'       => $data['amount'],
                'mode'         => $data['mode'] ?? 'cash',
                'reference'    => $data['reference'] ?? null,
                'notes'        => $data['notes'] ?? null,
                'is_advance'   => $isAdvance,
            ]);

            $customer = Customer::forCompany($companyId)->lockForUpdate()->find($data['customer_id']);
            if ($customer) {
                if ($isAdvance) {
                    $customer->advance_balance = (float) $customer->advance_balance + (float) $data['amount'];
                } else {
                    $allocated = 0.0;
                    foreach ($allocations as $row) {
                        $this->assertSaleForCustomer($companyId, $row['sale_id'], $data['customer_id']);
                        $receipt->allocations()->create([
                            'sale_id' => $row['sale_id'],
                            'amount'  => $row['amount'],
                        ]);
                        $this->syncSalePaid($row['sale_id']);
                        $allocated += (float) $row['amount'];
                    }

                    $remainder = round((float) $data['amount'] - $allocated, 2);
                    if ($remainder > 0.005 && $allocations !== []) {
                        $customer->advance_balance = (float) $customer->advance_balance + $remainder;
                    }

                    $againstOutstanding = $allocations !== [] ? $allocated : (float) $data['amount'];
                    $customer->outstanding = (float) $customer->outstanding - $againstOutstanding;
                }
                $customer->save();
            }

            if (! $isAdvance && $allocations === [] && ! empty($data['sale_id'])) {
                $this->syncSalePaid($data['sale_id']);
            }

            return $receipt->load(['customer:id,name', 'sale:id,sale_no', 'allocations']);
        });

        try {
            app(AccountingEngine::class)->postReceipt($receipt);
        } catch (\Throwable $e) {
            Log::warning('Receipt recorded but ledger post failed', [
                'receipt_id' => $receipt->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $receipt;
    }

    /** Advance collected on booking — increases advance_balance, not AR. */
    public function recordAdvance(int|string $companyId, array $data, ?string $advanceOrderId = null, ?int $userId = null): CustomerReceipt
    {
        $receipt = DB::transaction(function () use ($companyId, $data, $advanceOrderId, $userId) {
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
                'is_advance'       => true,
            ]);

            $customer = Customer::forCompany($companyId)->lockForUpdate()->find($data['customer_id']);
            if ($customer) {
                $customer->advance_balance = (float) $customer->advance_balance + (float) $data['amount'];
                $customer->save();
            }

            return $receipt->load(['customer:id,name']);
        });

        try {
            app(AccountingEngine::class)->postReceipt($receipt);
        } catch (\Throwable $e) {
            Log::warning('Advance receipt recorded but ledger post failed', [
                'receipt_id' => $receipt->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $receipt;
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
                app(AccountingEngine::class)->cancelBySource('customer_receipt', $receipt->id);
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

        $receipt = CustomerReceipt::create([
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
        try {
            app(AccountingEngine::class)->postReceipt($receipt);
        } catch (\Throwable $e) {
            Log::warning('POS receipt recorded but ledger post failed', [
                'receipt_id' => $receipt->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $receipt;
    }

    /** Remove auto receipts from a cancelled sale without reversing outstanding (none was adjusted). */
    public function voidForSale(Sale $sale): void
    {
        $receipts = CustomerReceipt::query()->where('sale_id', $sale->id)->get();
        foreach ($receipts as $receipt) {
            app(AccountingEngine::class)->cancelBySource('customer_receipt', $receipt->id);
            $receipt->delete();
        }
    }

    /**
     * Apply existing customer advance to a confirmed invoice.
     * Does not create a cash/bank movement — cash already landed when the advance was received.
     */
    public function applyAdvance(int|string $companyId, array $data, ?int $userId = null): CustomerReceipt
    {
        return DB::transaction(function () use ($companyId, $data) {
            $amount = (float) $data['amount'];
            $customer = Customer::forCompany($companyId)->lockForUpdate()->find($data['customer_id']);
            if (! $customer) {
                throw ValidationException::withMessages(['customer_id' => 'Customer not found.']);
            }

            $advance = (float) $customer->advance_balance;
            if ($amount > $advance + 1e-6) {
                throw ValidationException::withMessages([
                    'amount' => "Amount cannot exceed available advance ({$advance}).",
                ]);
            }

            $sale = Sale::forCompany($companyId)->lockForUpdate()->find($data['sale_id']);
            if (! $sale || $sale->status !== 'confirmed') {
                throw ValidationException::withMessages(['sale_id' => 'Advance can only be applied to a confirmed invoice.']);
            }
            if ((string) $sale->customer_id !== (string) $customer->id) {
                throw ValidationException::withMessages(['sale_id' => 'Invoice does not belong to the selected customer.']);
            }

            $invoice = round(max(0, (float) $sale->grand_total - (float) $sale->loyalty_discount), 2);
            $already = (float) CustomerReceipt::query()->where('sale_id', $sale->id)->sum('amount')
                + (float) CustomerReceiptAllocation::query()->where('sale_id', $sale->id)->sum('amount');
            if ($already <= 0.005) {
                $already = (float) $sale->amount_paid;
            }
            $balance = round($invoice - $already, 2);
            if ($amount > $balance + 1e-6) {
                throw ValidationException::withMessages([
                    'amount' => "Amount cannot exceed the remaining invoice balance ({$balance}).",
                ]);
            }

            $receipt = CustomerReceipt::create([
                'company_id'           => $companyId,
                'customer_id'          => $customer->id,
                'sale_id'              => $sale->id,
                'receipt_no'           => $this->nextReceiptNo($companyId),
                'receipt_date'         => now()->toDateString(),
                'amount'               => $amount,
                'mode'                 => 'cash',
                'notes'                => 'Advance applied to '.$sale->sale_no,
                'applied_from_advance' => true,
            ]);

            $customer->advance_balance = $advance - $amount;
            $customer->outstanding = (float) $customer->outstanding - $amount;
            $customer->save();

            $this->syncSalePaid($sale->id);

            return $receipt->load(['customer:id,name', 'sale:id,sale_no']);
        });
    }

    public function delete(CustomerReceipt $receipt): void
    {
        DB::transaction(function () use ($receipt) {
            $customer = Customer::forCompany($receipt->company_id)->lockForUpdate()->find($receipt->customer_id);
            $saleIds = $receipt->allocations()->pluck('sale_id')->all();
            if ($receipt->sale_id) {
                $saleIds[] = $receipt->sale_id;
            }

            if ($customer) {
                if ($receipt->applied_from_advance) {
                    $customer->advance_balance = (float) $customer->advance_balance + (float) $receipt->amount;
                    $customer->outstanding = (float) $customer->outstanding + (float) $receipt->amount;
                } elseif ($receipt->is_advance || $receipt->advance_order_id) {
                    $customer->advance_balance = max(0, (float) $customer->advance_balance - (float) $receipt->amount);
                } else {
                    $allocated = (float) $receipt->allocations()->sum('amount');
                    $againstOutstanding = $allocated > 0.005 ? $allocated : (float) $receipt->amount;
                    $remainder = round((float) $receipt->amount - $againstOutstanding, 2);
                    $customer->outstanding = (float) $customer->outstanding + $againstOutstanding;
                    if ($remainder > 0.005) {
                        $customer->advance_balance = max(0, (float) $customer->advance_balance - $remainder);
                    }
                }
                $customer->save();
            }

            app(AccountingEngine::class)->cancelBySource('customer_receipt', $receipt->id);
            $receipt->allocations()->delete();
            $receipt->delete();

            foreach (array_unique($saleIds) as $saleId) {
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
            ->with('customer:id,name,credit_days,advance_balance,outstanding')
            ->withSum('customerReceipts as received_sum', 'amount')
            ->withSum('receiptAllocations as allocated_sum', 'amount')
            ->orderByDesc('sale_date')
            ->get()
            ->map(function (Sale $s) {
                $invoice = round(max(0, (float) $s->grand_total - (float) $s->loyalty_discount), 2);
                $received = (float) ($s->received_sum ?? 0) + (float) ($s->allocated_sum ?? 0);
                // Legacy sales recorded tender only on amount_paid (no receipt row).
                if ($received <= 0.005) {
                    $received = (float) $s->amount_paid;
                }
                $balance = round($invoice - $received, 2);
                $due = $s->sale_date?->copy()->addDays((int) ($s->customer->credit_days ?? 0));

                return [
                    'id'               => $s->id,
                    'sale_no'          => $s->sale_no,
                    'company_id'       => $s->company_id,
                    'customer_id'      => $s->customer_id,
                    'customer_name'    => $s->customer?->name,
                    'advance_balance'  => round((float) ($s->customer?->advance_balance ?? 0), 2),
                    'outstanding'      => round((float) ($s->customer?->outstanding ?? 0), 2),
                    'invoice_total'    => $invoice,
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
        $paid += (float) CustomerReceiptAllocation::query()
            ->where('sale_id', $saleId)
            ->sum('amount');

        $sale->amount_paid = round($paid, 2);
        $sale->save();
    }

    private function assertSaleForCustomer(int|string $companyId, string $saleId, string $customerId): void
    {
        $sale = Sale::forCompany($companyId)->find($saleId);
        if (! $sale || (string) $sale->customer_id !== (string) $customerId) {
            throw ValidationException::withMessages(['allocations' => 'Allocation invoice does not belong to the customer.']);
        }
    }
}
