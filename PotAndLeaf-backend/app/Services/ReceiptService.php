<?php

namespace App\Services;

use App\Models\AdvanceOrder;
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
            ->when(filled($filters['mode'] ?? null), fn ($q) => $q->where('mode', $filters['mode']))
            ->listFilters($filters, 'receipt_date', ['receipt_no', 'reference'], ['customer' => 'name'])
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

            if (! $isAdvance) {
                $this->assertNotMoreThanOwed($companyId, $data, $allocations);
            }
            // On account (no bill picked): pays the outstanding from before first,
            // then the oldest unpaid bills — worked out before this receipt exists.
            $onAccountPlain = ! $isAdvance && $allocations === [] && empty($data['sale_id']);
            $openingBefore = 0.0;
            if ($onAccountPlain) {
                $cust = Customer::forCompany($companyId)->find($data['customer_id']);
                $openingBefore = $cust ? $this->openingLeft($cust) : 0.0;
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

            if ($onAccountPlain) {
                $this->applyToOldestBills($receipt, round((float) $data['amount'] - min((float) $data['amount'], $openingBefore), 2));
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

    /**
     * A sale created by fulfilling an advance order already counts the
     * booking advance as paid (amount_paid). On confirm, move that advance
     * out of the customer's advance balance and link its receipts to the
     * sale, so the same money cannot be applied to another invoice and no
     * second (cash) receipt is created for it. Returns the amount consumed.
     */
    public function consumeAdvanceOrderAdvance(Sale $sale): float
    {
        if (! $sale->customer_id) {
            return 0.0;
        }

        $order = AdvanceOrder::query()
            ->where('company_id', $sale->company_id)
            ->where('sale_id', $sale->id)
            ->first();
        if (! $order) {
            return 0.0;
        }

        $receipts = CustomerReceipt::query()
            ->where('advance_order_id', $order->id)
            ->whereNull('sale_id')
            ->lockForUpdate()
            ->get();
        $total = round((float) $receipts->sum('amount'), 2);
        if ($total <= 0) {
            return 0.0;
        }

        $customer = Customer::forCompany($sale->company_id)->lockForUpdate()->find($sale->customer_id);
        if ($customer) {
            $customer->advance_balance = max(0, round((float) $customer->advance_balance - $total, 2));
            $customer->save();
        }

        CustomerReceipt::query()->whereIn('id', $receipts->pluck('id'))->update(['sale_id' => $sale->id]);

        return $total;
    }

    /** Remove auto receipts from a cancelled sale without reversing outstanding (none was adjusted). */
    public function voidForSale(Sale $sale): void
    {
        // Booking advances used on this sale go back to the customer's
        // advance balance instead of being deleted with the sale.
        $advanceReceipts = CustomerReceipt::query()
            ->where('sale_id', $sale->id)
            ->whereNotNull('advance_order_id')
            ->get();
        if ($advanceReceipts->isNotEmpty()) {
            $customer = Customer::forCompany($sale->company_id)->lockForUpdate()->find($sale->customer_id);
            if ($customer) {
                $customer->advance_balance = round((float) $customer->advance_balance + (float) $advanceReceipts->sum('amount'), 2);
                $customer->save();
            }
            CustomerReceipt::query()->whereIn('id', $advanceReceipts->pluck('id'))->update(['sale_id' => null]);
        }

        // Customer advance used on this sale goes back to the advance.
        $fromAdvance = (float) CustomerReceipt::query()->where('sale_id', $sale->id)->where('applied_from_advance', true)->sum('amount');
        if ($fromAdvance > 0.005) {
            $customer = Customer::forCompany($sale->company_id)->lockForUpdate()->find($sale->customer_id);
            if ($customer) {
                $customer->advance_balance = round((float) $customer->advance_balance + $fromAdvance, 2);
                $customer->save();
            }
        }

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
                } elseif ($receipt->advance_order_id && $receipt->sale_id) {
                    // A booking advance already used on a confirmed sale: the
                    // money now pays that sale, so removing it re-opens the debt.
                    $customer->outstanding = (float) $customer->outstanding + (float) $receipt->amount;
                } elseif ($receipt->is_advance || $receipt->advance_order_id) {
                    $customer->advance_balance = max(0, (float) $customer->advance_balance - (float) $receipt->amount);
                } elseif ($receipt->auto_applied) {
                    // On-account money spread over bills: all of it was taken off "Owes you".
                    $customer->outstanding = (float) $customer->outstanding + (float) $receipt->amount;
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

    /**
     * The customer's "Outstanding (from before)" still unpaid. Money received
     * "On account" (no bill picked, not an advance) pays this old amount first.
     * Example: entered 5,000, received 2,000 on account → 3,000 left.
     */
    public function openingLeft(Customer $customer): float
    {
        $opening = round((float) $customer->opening_balance, 2);
        if ($opening <= 0.005) {
            return max(0, $opening);
        }
        $onAccount = $this->onAccountUnapplied($customer);

        return round(max(0, min($opening - $onAccount, (float) $customer->outstanding)), 2);
    }

    /**
     * Money received "on account" that did not go to a bill: plain on-account
     * receipts, plus the part of an auto-applied one not spread over bills.
     */
    private function onAccountUnapplied(Customer $customer): float
    {
        $plain = (float) CustomerReceipt::query()
            ->where('customer_id', $customer->id)
            ->where('is_advance', false)
            ->whereNull('sale_id')
            ->whereNull('advance_order_id')
            ->where('auto_applied', false)
            ->whereDoesntHave('allocations')
            ->sum('amount');
        $auto = CustomerReceipt::query()
            ->where('customer_id', $customer->id)
            ->where('auto_applied', true)
            ->withSum('allocations as allocated', 'amount')
            ->get(['id', 'amount']);
        $autoLeft = (float) $auto->sum(fn ($r) => max(0, (float) $r->amount - (float) ($r->allocated ?? 0)));

        return round($plain + $autoLeft, 2);
    }

    /**
     * Spread on-account money over the customer's unpaid bills, oldest first,
     * so each bill shows what was paid on it. Bills whose "paid" figure is not
     * backed by receipts (very old sales) are left alone.
     */
    public function applyToOldestBills(CustomerReceipt $receipt, float $amount): void
    {
        $receipt->forceFill(['auto_applied' => true])->save();
        if ($amount <= 0.005) {
            return;
        }
        $returned = \App\Models\SalesReturn::query()
            ->where('customer_id', $receipt->customer_id)->where('status', 'confirmed')
            ->selectRaw('sale_id, SUM(grand_total) as t')->groupBy('sale_id')->pluck('t', 'sale_id');
        $sales = Sale::query()
            ->where('company_id', $receipt->company_id)
            ->where('customer_id', $receipt->customer_id)
            ->where('status', 'confirmed')
            ->withSum('customerReceipts as rec_sum', 'amount')
            ->withSum('receiptAllocations as alloc_sum', 'amount')
            ->orderBy('sale_date')->orderBy('created_at')
            ->get();
        foreach ($sales as $sale) {
            if ($amount <= 0.005) {
                break;
            }
            $backed = (float) ($sale->rec_sum ?? 0) + (float) ($sale->alloc_sum ?? 0);
            if ((float) $sale->amount_paid > $backed + 0.005) {
                continue;
            }
            $unpaid = round((float) $sale->grand_total - (float) $sale->loyalty_discount - (float) $sale->amount_paid - (float) ($returned[$sale->id] ?? 0), 2);
            if ($unpaid <= 0.005) {
                continue;
            }
            $part = round(min($unpaid, $amount), 2);
            $receipt->allocations()->create(['sale_id' => $sale->id, 'amount' => $part]);
            $this->syncSalePaid($sale->id);
            $amount = round($amount - $part, 2);
        }
    }

    /**
     * "Owes you" worked out again from the records:
     *   outstanding from before + still unpaid on confirmed bills (less returns)
     *   − money received "on account" (no bill picked, not an advance).
     * Used to spot and repair a customer whose stored "Owes you" does not match.
     */
    public function owesFromRecords(Customer $customer): array
    {
        $returned = \App\Models\SalesReturn::query()
            ->where('customer_id', $customer->id)->where('status', 'confirmed')
            ->selectRaw('sale_id, SUM(grand_total) as t')->groupBy('sale_id')->pluck('t', 'sale_id');
        $unpaidBills = round((float) Sale::query()
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)->where('status', 'confirmed')
            ->get(['id', 'grand_total', 'loyalty_discount', 'amount_paid'])
            ->sum(fn ($s) => max(0, (float) $s->grand_total - (float) $s->loyalty_discount - (float) $s->amount_paid - (float) ($returned[$s->id] ?? 0))), 2);
        $onAccount = $this->onAccountUnapplied($customer);
        $opening = round(max(0, (float) $customer->opening_balance), 2);
        $expected = round(max(0, $opening + $unpaidBills - $onAccount), 2);

        return [
            'from_before'  => $opening,
            'unpaid_bills' => $unpaidBills,
            'on_account'   => $onAccount,
            'expected'     => $expected,
            'stored'       => round((float) $customer->outstanding, 2),
            'matches'      => abs($expected - (float) $customer->outstanding) < 0.01,
        ];
    }

    private function nextReceiptNo(int|string $companyId): string
    {
        return \App\Support\Numbering\DocumentNumber::next(CustomerReceipt::query()->where('company_id', $companyId), 'receipt_no', 'RCP-', 6);
    }

    /**
     * Money that is not an advance cannot be more than is owed, so "Owes you"
     * never goes below zero:
     *  - On account (no bill chosen): up to what the customer owes in all.
     *  - Against a bill: up to what is left to pay on that bill.
     * Extra money should be recorded as a customer advance.
     */
    private function assertNotMoreThanOwed(int|string $companyId, array $data, array $allocations): void
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        $tip = ' Tick “Record as customer advance” to keep extra money as advance.';
        $left = function (string $saleId) use ($companyId, $data): float {
            $sale = Sale::forCompany($companyId)->find($saleId);
            if (! $sale || (string) $sale->customer_id !== (string) $data['customer_id']) {
                throw ValidationException::withMessages(['sale_id' => 'This invoice does not belong to the selected customer.']);
            }

            return round(max(0, (float) $sale->grand_total - (float) $sale->loyalty_discount - (float) $sale->amount_paid), 2);
        };

        if ($allocations !== []) {
            foreach ($allocations as $i => $row) {
                $max = $left($row['sale_id']);
                if ((float) $row['amount'] > $max + 0.005) {
                    throw ValidationException::withMessages([
                        "allocations.{$i}.amount" => 'Only ₹'.number_format($max, 2).' is left to pay on this invoice.',
                    ]);
                }
            }

            return;
        }

        if (filled($data['sale_id'] ?? null)) {
            $max = $left($data['sale_id']);
            if ($amount > $max + 0.005) {
                throw ValidationException::withMessages([
                    'amount' => 'Only ₹'.number_format($max, 2).' is left to pay on this invoice. Enter up to ₹'.number_format($max, 2).'.'.$tip,
                ]);
            }

            return;
        }

        $customer = Customer::forCompany($companyId)->find($data['customer_id']);
        $owed = round(max(0, (float) ($customer?->outstanding ?? 0)), 2);
        if ($amount > $owed + 0.005) {
            throw ValidationException::withMessages([
                'amount' => $owed > 0
                    ? ($customer->name.' owes you ₹'.number_format($owed, 2).'. Enter up to ₹'.number_format($owed, 2).'.'.$tip)
                    : ($customer?->name.' owes you nothing now.'.$tip),
            ]);
        }
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
