<?php

namespace App\Services;

use App\Http\Requests\Payment\StoreSupplierPaymentRequest;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Services\AccountingEngine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(private readonly ActivityLogService $activity) {}

    public function list(int|string|null $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return SupplierPayment::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->with(['supplier:id,name', 'purchase:id,purchase_no', 'allocations.purchase:id,purchase_no'])
            ->when(filled($filters['supplier_id'] ?? null), fn ($q) => $q->where('supplier_id', $filters['supplier_id']))
            ->when(filled($filters['mode'] ?? null), fn ($q) => $q->where('mode', $filters['mode']))
            ->listFilters($filters, 'payment_date', ['payment_no', 'reference'], ['supplier' => 'name'])
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function record(int|string $companyId, array $data, ?int $userId = null): SupplierPayment
    {
        $payment = DB::transaction(function () use ($companyId, $data, $userId) {
            $amount = (float) $data['amount'];
            $isAdvance = (bool) ($data['is_advance'] ?? false);
            $allocations = array_values(array_filter(
                $data['allocations'] ?? [],
                fn ($row) => (float) ($row['amount'] ?? 0) > 0 && filled($row['purchase_id'] ?? null),
            ));

            if ($isAdvance && ($allocations !== [] || filled($data['purchase_id'] ?? null))) {
                throw ValidationException::withMessages([
                    'is_advance' => 'An advance cannot be allocated to a GRN in the same step. Record the advance, then apply it.',
                ]);
            }

            $supplier = Supplier::where('company_id', $companyId)->lockForUpdate()->find($data['supplier_id']);
            if (! $supplier) {
                throw ValidationException::withMessages(['supplier_id' => 'Supplier not found.']);
            }

            if (! $isAdvance) {
                $againstOutstanding = $allocations !== []
                    ? array_sum(array_map(fn ($r) => (float) $r['amount'], $allocations))
                    : $amount;
                $this->assertPayableAmount($supplier, $againstOutstanding, $companyId, $allocations === [] ? ($data['purchase_id'] ?? null) : null);
                foreach ($allocations as $row) {
                    $this->assertPayableAmount($supplier, (float) $row['amount'], $companyId, $row['purchase_id']);
                }
            }

            $payment = SupplierPayment::create([
                'company_id'   => $companyId,
                'supplier_id'  => $data['supplier_id'],
                'purchase_id'  => $allocations === [] ? ($data['purchase_id'] ?? null) : null,
                'payment_no'   => $this->nextPaymentNo($companyId),
                'payment_date' => $data['payment_date'],
                'amount'       => $amount,
                'mode'         => $data['mode'] ?? 'cash',
                'reference'    => $data['reference'] ?? null,
                'notes'        => $data['notes'] ?? null,
                'is_advance'   => $isAdvance,
            ]);

            if ($isAdvance) {
                $supplier->advance_balance = (float) $supplier->advance_balance + $amount;
            } else {
                $allocated = 0.0;
                foreach ($allocations as $row) {
                    $payment->allocations()->create([
                        'purchase_id' => $row['purchase_id'],
                        'amount'      => $row['amount'],
                    ]);
                    $this->syncPurchasePaid($row['purchase_id']);
                    $allocated += (float) $row['amount'];
                }
                $remainder = round($amount - $allocated, 2);
                if ($remainder > 0.005 && $allocations !== []) {
                    $supplier->advance_balance = (float) $supplier->advance_balance + $remainder;
                }
                $againstOutstanding = $allocations !== [] ? $allocated : $amount;
                $supplier->outstanding = (float) $supplier->outstanding - $againstOutstanding;
            }
            $supplier->save();

            if (! $isAdvance && $allocations === [] && ! empty($data['purchase_id'])) {
                $this->syncPurchasePaid($data['purchase_id']);
            }
            if (! $isAdvance) {
                $this->settleOnAccount($supplier);
            }

            $this->activity->log($companyId, $userId, 'create', 'payment', 'supplier_payment', $payment->id, "Payment {$payment->payment_no} recorded");

            return $payment->load(['supplier:id,name', 'purchase:id,purchase_no', 'allocations']);
        });

        try {
            app(AccountingEngine::class)->postPayment($payment);
        } catch (\Throwable $e) {
            Log::warning('Payment recorded but ledger post failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $payment;
    }

    /**
     * Apply existing supplier advance to a confirmed GRN.
     * Does not create a cash/bank movement — cash already left when the advance was paid.
     */
    public function applyAdvance(int|string $companyId, array $data, ?int $userId = null): SupplierPayment
    {
        return DB::transaction(function () use ($companyId, $data, $userId) {
            $amount = (float) $data['amount'];
            $supplier = Supplier::where('company_id', $companyId)->lockForUpdate()->find($data['supplier_id']);
            if (! $supplier) {
                throw ValidationException::withMessages(['supplier_id' => 'Supplier not found.']);
            }

            $advance = (float) $supplier->advance_balance;
            if ($amount > $advance + 1e-6) {
                throw ValidationException::withMessages([
                    'amount' => "Amount cannot exceed available advance ({$advance}).",
                ]);
            }

            $purchase = Purchase::forCompany($companyId)->lockForUpdate()->find($data['purchase_id']);
            if (! $purchase || ! $purchase->isConfirmed()) {
                throw ValidationException::withMessages(['purchase_id' => 'Advance can only be applied to a confirmed purchase.']);
            }
            if ((string) $purchase->supplier_id !== (string) $supplier->id) {
                throw ValidationException::withMessages(['purchase_id' => 'Purchase does not belong to the selected supplier.']);
            }

            $balance = StoreSupplierPaymentRequest::remainingPurchaseBalance($purchase);
            if ($amount > $balance + 1e-6) {
                throw ValidationException::withMessages([
                    'amount' => "Amount cannot exceed the remaining GRN balance ({$balance}).",
                ]);
            }

            $payment = SupplierPayment::create([
                'company_id'           => $companyId,
                'supplier_id'          => $supplier->id,
                'purchase_id'          => $purchase->id,
                'payment_no'           => $this->nextPaymentNo($companyId),
                'payment_date'         => now()->toDateString(),
                'amount'               => $amount,
                'mode'                 => 'cash',
                'notes'                => 'Advance applied to '.$purchase->purchase_no,
                'applied_from_advance' => true,
            ]);

            $supplier->advance_balance = $advance - $amount;
            $supplier->outstanding = (float) $supplier->outstanding - $amount;
            $supplier->save();
            $this->consumeAdvanceFifo($supplier->id, $amount);

            $this->syncPurchasePaid($purchase->id);
            $this->settleOnAccount($supplier);
            $this->activity->log($companyId, $userId, 'create', 'payment', 'supplier_payment', $payment->id, "Advance applied {$payment->payment_no}");

            return $payment->load(['supplier:id,name', 'purchase:id,purchase_no']);
        });
    }

    public function delete(SupplierPayment $payment, ?int $userId = null): void
    {
        DB::transaction(function () use ($payment, $userId) {
            $companyId = $payment->company_id;
            $paymentNo = $payment->payment_no;

            $supplier = Supplier::where('company_id', $payment->company_id)->lockForUpdate()->find($payment->supplier_id);
            $purchaseIds = $payment->allocations()->pluck('purchase_id')->all();
            if ($payment->purchase_id) {
                $purchaseIds[] = $payment->purchase_id;
            }

            if ($supplier) {
                if ($payment->applied_from_advance) {
                    $supplier->advance_balance = (float) $supplier->advance_balance + (float) $payment->amount;
                    $supplier->outstanding = (float) $supplier->outstanding + (float) $payment->amount;
                } elseif ($payment->is_advance) {
                    $unapplied = max(0, round((float) $payment->amount - (float) ($payment->adjusted_amount ?? 0), 2));
                    $supplier->advance_balance = max(0, (float) $supplier->advance_balance - $unapplied);
                } else {
                    // Only allocations the user chose; automatic ones were still "against what was owed".
                    $allocated = (float) $payment->allocations()->where('auto_applied', false)->sum('amount');
                    $againstOutstanding = $allocated > 0.005 ? $allocated : (float) $payment->amount;
                    $remainder = round((float) $payment->amount - $againstOutstanding, 2);
                    $supplier->outstanding = (float) $supplier->outstanding + $againstOutstanding;
                    if ($remainder > 0.005) {
                        $supplier->advance_balance = max(0, (float) $supplier->advance_balance - $remainder);
                    }
                }
                $supplier->save();
            }
            app(AccountingEngine::class)->cancelBySource('supplier_payment', $payment->id);
            $payment->allocations()->delete();
            $payment->delete();

            foreach (array_unique($purchaseIds) as $purchaseId) {
                $this->syncPurchasePaid($purchaseId);
            }
            if ($supplier) {
                $this->settleOnAccount($supplier->fresh());
            }

            $this->activity->log($companyId, $userId, 'delete', 'payment', 'supplier_payment', $payment->id, "Payment {$paymentNo} removed");
        });
    }

    /** Confirmed purchases with paid / balance / status / due date. */
    public function payables(int|string|null $companyId, int|string|null $supplierId = null): array
    {
        return Purchase::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->where('status', 'confirmed')
            ->when(filled($supplierId), fn ($q) => $q->where('supplier_id', $supplierId))
            ->with('supplier:id,name,credit_days,advance_balance,outstanding')
            ->withSum('supplierPayments as paid_sum', 'amount')
            ->withSum('paymentAllocations as allocated_sum', 'amount')
            ->withSum(['purchaseReturns as returned_sum' => fn ($q) => $q->where('status', 'confirmed')], 'grand_total')
            ->orderByDesc('purchase_date')
            ->get()
            ->map(function (Purchase $p) {
                // What this bill added to the supplier's outstanding.
                $total = $p->supplierPayable();
                $paid = (float) ($p->paid_sum ?? 0) + (float) ($p->allocated_sum ?? 0);
                $balance = round($total - $paid - (float) ($p->returned_sum ?? 0), 2);
                $status = $balance <= 0.005 ? 'paid' : ($paid <= 0.005 ? 'unpaid' : 'partial');
                $due = $p->purchase_date?->copy()->addDays((int) ($p->supplier->credit_days ?? 0));

                return [
                    'id'              => $p->id,
                    'purchase_no'     => $p->purchase_no,
                    'company_id'      => $p->company_id,
                    'supplier_id'     => $p->supplier_id,
                    'supplier_name'   => $p->supplier?->name,
                    'advance_balance' => round((float) ($p->supplier?->advance_balance ?? 0), 2),
                    'outstanding'     => round((float) ($p->supplier?->outstanding ?? 0), 2),
                    'invoice_total' => $total,
                    'paid'          => round($paid, 2),
                    'balance'       => $balance,
                    'status'        => $status,
                    'due_date'      => $due?->toDateString(),
                ];
            })
            ->values()
            ->all();
    }

    /** Re-check under row lock (same rules as StoreSupplierPaymentRequest). */
    private function assertPayableAmount(
        Supplier $supplier,
        float $amount,
        int|string $companyId,
        int|string|null $purchaseId = null,
    ): void {
        $outstanding = (float) $supplier->outstanding;
        // Never pay more than is owed in all — also when paying a particular bill.
        if ($amount > $outstanding + 1e-6) {
            throw ValidationException::withMessages([
                'amount' => "Payment amount cannot exceed what you owe this supplier ({$outstanding}). Use \"This is an advance\" to pay extra.",
            ]);
        }

        if (! filled($purchaseId)) {
            return;
        }

        $purchase = Purchase::forCompany($companyId)->lockForUpdate()->find($purchaseId);
        if (! $purchase || ! $purchase->isConfirmed()) {
            throw ValidationException::withMessages([
                'purchase_id' => 'Payment can only be allocated to a confirmed purchase.',
            ]);
        }

        if ((string) $purchase->supplier_id !== (string) $supplier->id) {
            throw ValidationException::withMessages([
                'purchase_id' => 'Purchase does not belong to the selected supplier.',
            ]);
        }

        $balance = StoreSupplierPaymentRequest::remainingPurchaseBalance($purchase);
        if ($amount > $balance + 1e-6) {
            throw ValidationException::withMessages([
                'amount' => "Payment amount cannot exceed the remaining GRN balance ({$balance}).",
            ]);
        }
    }

    /**
     * How "you owe" is made up, for the payment form:
     * opening balance + bills − paid − advance used − goods returned (= outstanding).
     * Anything that does not come from these (old corrections) shows as one adjustment line.
     */
    public function owedBreakdown(Supplier $supplier): array
    {
        $bills = Purchase::query()->where('supplier_id', $supplier->id)->where('status', 'confirmed')->get();
        $billsTotal = round($bills->sum(fn (Purchase $p) => $p->supplierPayable()), 2);

        $paid = 0.0;
        $advanceUsed = 0.0;
        foreach (SupplierPayment::query()->where('supplier_id', $supplier->id)->with('allocations')->get() as $p) {
            if ($p->applied_from_advance) {
                $advanceUsed += (float) $p->amount;
            } elseif (! $p->is_advance) {
                $manual = (float) $p->allocations->where('auto_applied', false)->sum('amount');
                $paid += $manual > 0.005 ? $manual : (float) $p->amount;  // the rest of a split payment went to advance
            }
        }
        $returned = (float) \App\Models\PurchaseReturn::query()->where('supplier_id', $supplier->id)->where('status', 'confirmed')
            ->get()->sum(fn ($r) => max(0, (float) $r->grand_total - (float) $r->advance_credit));

        $opening = round((float) $supplier->opening_balance, 2);
        $owed = round((float) $supplier->outstanding, 2);
        $worked = round($opening + $billsTotal - $paid - $advanceUsed - $returned, 2);

        return [
            'opening_balance' => $opening,
            'opening_left'    => $this->openingLeft($supplier),
            'bills_total'     => $billsTotal,
            'bills_count'     => $bills->count(),
            'paid'            => round($paid, 2),
            'advance_used'    => round($advanceUsed, 2),
            'returned'        => round($returned, 2),
            'adjustment'      => round($owed - $worked, 2),
            'you_owe'         => $owed,
            'advance_balance' => round((float) $supplier->advance_balance, 2),
        ];
    }

    /**
     * The supplier's "Outstanding (from before)" still unpaid: what is owed in all
     * minus what is still open on bills, never below 0 or above the amount entered.
     * Example: entered 10,000, bills open 5,000, owed in all 12,000 → 7,000 left
     * (3,000 of the old outstanding has been paid).
     */
    public function openingLeft(Supplier $supplier): float
    {
        $opening = round((float) $supplier->opening_balance, 2);
        if ($opening <= 0.005) {
            return max(0, $opening);
        }
        $billsOpen = Purchase::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', 'confirmed')
            ->get()
            ->sum(fn (Purchase $p) => StoreSupplierPaymentRequest::remainingPurchaseBalance($p));

        return round(min($opening, max(0, (float) $supplier->outstanding - $billsOpen)), 2);
    }

    /**
     * Money paid "on account" (not against a bill) pays the old dues / opening
     * balance first, then the oldest bills. Worked out again from scratch each
     * time, so it stays right after any payment is added or removed:
     * the bills' unpaid total may never be more than what is owed in all.
     */
    public function settleOnAccount(Supplier $supplier): void
    {
        $auto = SupplierPaymentAllocation::query()
            ->where('auto_applied', true)
            ->whereIn('supplier_payment_id', SupplierPayment::query()->where('supplier_id', $supplier->id)->select('id'));
        $touched = (clone $auto)->pluck('purchase_id')->all();
        $auto->delete();
        foreach (array_unique($touched) as $pid) {
            $this->syncPurchasePaid($pid);
        }

        $bills = Purchase::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', 'confirmed')
            ->orderBy('purchase_date')->orderBy('created_at')
            ->get()
            ->map(fn (Purchase $p) => ['purchase' => $p, 'balance' => StoreSupplierPaymentRequest::remainingPurchaseBalance($p)])
            ->filter(fn ($b) => $b['balance'] > 0.005)
            ->values();
        $excess = round($bills->sum('balance') - max(0, (float) $supplier->fresh()->outstanding), 2);
        if ($excess <= 0.005) {
            return;
        }

        // Payments made "against what was owed": not an advance, no bill, no hand-picked bills.
        $sources = SupplierPayment::query()
            ->where('supplier_id', $supplier->id)
            ->where('is_advance', false)
            ->where(fn ($q) => $q->whereNull('applied_from_advance')->orWhere('applied_from_advance', false))
            ->whereNull('purchase_id')
            ->whereDoesntHave('allocations', fn ($q) => $q->where('auto_applied', false))
            ->orderBy('payment_date')->orderBy('created_at')
            ->get();

        $changed = [];
        $bi = 0;
        $billLeft = $bills[0]['balance'] ?? 0;
        // The earliest on-account money paid the old dues / opening balance; only what
        // is left after that goes to bills.
        $skip = max(0, round($sources->sum(fn ($p) => (float) $p->amount) - $excess, 2));
        foreach ($sources as $payment) {
            $cap = (float) $payment->amount;
            $used = min($cap, $skip);
            $cap = round($cap - $used, 2);
            $skip = round($skip - $used, 2);
            while ($excess > 0.005 && $cap > 0.005 && $bi < $bills->count()) {
                $take = round(min($excess, $cap, $billLeft), 2);
                if ($take > 0.005) {
                    SupplierPaymentAllocation::create([
                        'supplier_payment_id' => $payment->id,
                        'purchase_id' => $bills[$bi]['purchase']->id,
                        'amount' => $take,
                        'auto_applied' => true,
                    ]);
                    $changed[] = $bills[$bi]['purchase']->id;
                }
                $excess = round($excess - $take, 2);
                $cap = round($cap - $take, 2);
                $billLeft = round($billLeft - $take, 2);
                if ($billLeft <= 0.005) {
                    $bi++;
                    $billLeft = $bills[$bi]['balance'] ?? 0;
                }
            }
            if ($excess <= 0.005) {
                break;
            }
        }
        foreach (array_unique($changed) as $pid) {
            $this->syncPurchasePaid($pid);
        }
    }

    private function syncPurchasePaid(string $purchaseId): void
    {
        $purchase = Purchase::lockForUpdate()->find($purchaseId);
        if (! $purchase) {
            return;
        }

        $paid = (float) SupplierPayment::query()
            ->where('purchase_id', $purchaseId)
            ->sum('amount');
        $paid += (float) SupplierPaymentAllocation::query()
            ->where('purchase_id', $purchaseId)
            ->sum('amount');

        $purchase->amount_paid = round($paid, 2);
        $purchase->save();
    }

    /** Mark the oldest unapplied supplier advances as consumed, without a second cash entry. */
    private function consumeAdvanceFifo(string $supplierId, float $amount): void
    {
        $left = round($amount, 2);
        $advances = SupplierPayment::query()
            ->where('supplier_id', $supplierId)
            ->where('is_advance', true)
            ->orderBy('payment_date')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        foreach ($advances as $advance) {
            if ($left <= 0.005) {
                break;
            }
            $remaining = round((float) $advance->amount - (float) ($advance->adjusted_amount ?? 0), 2);
            if ($remaining <= 0.005) {
                continue;
            }
            $take = round(min($remaining, $left), 2);
            $advance->adjusted_amount = round((float) ($advance->adjusted_amount ?? 0) + $take, 2);
            $advance->save();
            $left = round($left - $take, 2);
        }
    }

    private function nextPaymentNo(int|string $companyId): string
    {
        return \App\Support\Numbering\DocumentNumber::next(SupplierPayment::query()->where('company_id', $companyId), 'payment_no', 'PAY-', 6);
    }
}
