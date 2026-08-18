<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(private readonly ActivityLogService $activity) {}

    public function list(int|string $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return SupplierPayment::query()
            ->forCompany($companyId)
            ->with(['supplier:id,name', 'purchase:id,purchase_no'])
            ->when(filled($filters['supplier_id'] ?? null), fn ($q) => $q->where('supplier_id', $filters['supplier_id']))
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function record(int|string $companyId, array $data, ?int $userId = null): SupplierPayment
    {
        return DB::transaction(function () use ($companyId, $data, $userId) {
            $payment = SupplierPayment::create([
                'company_id'   => $companyId,
                'supplier_id'  => $data['supplier_id'],
                'purchase_id'  => $data['purchase_id'] ?? null,
                'payment_no'   => $this->nextPaymentNo($companyId),
                'payment_date' => $data['payment_date'],
                'amount'       => $data['amount'],
                'mode'         => $data['mode'] ?? 'cash',
                'reference'    => $data['reference'] ?? null,
                'notes'        => $data['notes'] ?? null,
            ]);

            $supplier = Supplier::where('company_id', $companyId)->lockForUpdate()->find($data['supplier_id']);
            if ($supplier) {
                $supplier->outstanding = (float) $supplier->outstanding - (float) $data['amount'];
                $supplier->save();
            }

            if (! empty($data['purchase_id'])) {
                $this->syncPurchasePaid($data['purchase_id']);
            }

            $this->activity->log($companyId, $userId, 'create', 'payment', 'supplier_payment', $payment->id, "Payment {$payment->payment_no} recorded");

            return $payment->load(['supplier:id,name', 'purchase:id,purchase_no']);
        });
    }

    public function delete(SupplierPayment $payment, ?int $userId = null): void
    {
        DB::transaction(function () use ($payment, $userId) {
            $purchaseId = $payment->purchase_id;
            $companyId = $payment->company_id;
            $paymentNo = $payment->payment_no;

            $supplier = Supplier::where('company_id', $payment->company_id)->lockForUpdate()->find($payment->supplier_id);
            if ($supplier) {
                $supplier->outstanding = (float) $supplier->outstanding + (float) $payment->amount;
                $supplier->save();
            }
            $payment->delete();

            if ($purchaseId) {
                $this->syncPurchasePaid($purchaseId);
            }

            $this->activity->log($companyId, $userId, 'delete', 'payment', 'supplier_payment', $payment->id, "Payment {$paymentNo} removed");
        });
    }

    /** Confirmed purchases with paid / balance / status / due date. */
    public function payables(int|string $companyId, int|string|null $supplierId = null): array
    {
        return Purchase::query()
            ->forCompany($companyId)
            ->where('status', 'confirmed')
            ->when(filled($supplierId), fn ($q) => $q->where('supplier_id', $supplierId))
            ->with('supplier:id,name,credit_days')
            ->withSum('supplierPayments as paid_sum', 'amount')
            ->orderByDesc('purchase_date')
            ->get()
            ->map(function (Purchase $p) {
                $total = (float) $p->grand_total;
                $paid = (float) ($p->paid_sum ?? 0);
                $balance = round($total - $paid, 2);
                $status = $balance <= 0.005 ? 'paid' : ($paid <= 0.005 ? 'unpaid' : 'partial');
                $due = $p->purchase_date?->copy()->addDays((int) ($p->supplier->credit_days ?? 0));

                return [
                    'id'            => $p->id,
                    'purchase_no'   => $p->purchase_no,
                    'supplier_id'   => $p->supplier_id,
                    'supplier_name' => $p->supplier?->name,
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

    private function syncPurchasePaid(string $purchaseId): void
    {
        $purchase = Purchase::lockForUpdate()->find($purchaseId);
        if (! $purchase) {
            return;
        }

        $paid = (float) SupplierPayment::query()
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
