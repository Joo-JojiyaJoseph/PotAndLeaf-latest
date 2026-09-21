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

            $this->syncPurchasePaid($purchase->id);
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
                    $supplier->advance_balance = max(0, (float) $supplier->advance_balance - (float) $payment->amount);
                } else {
                    $allocated = (float) $payment->allocations()->sum('amount');
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
            ->orderByDesc('purchase_date')
            ->get()
            ->map(function (Purchase $p) {
                $total = (float) $p->grand_total;
                $paid = (float) ($p->paid_sum ?? 0) + (float) ($p->allocated_sum ?? 0);
                $balance = round($total - $paid, 2);
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
        if ($amount > $outstanding + 1e-6) {
            throw ValidationException::withMessages([
                'amount' => "Payment amount cannot exceed supplier outstanding ({$outstanding}).",
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

    private function nextPaymentNo(int|string $companyId): string
    {
        $count = SupplierPayment::withTrashed()->forCompany($companyId)->count();

        return 'PAY-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
