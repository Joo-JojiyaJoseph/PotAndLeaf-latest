<?php

namespace App\Http\Requests\Payment;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('payments.create', $this->writeCompanyId());
    }

    public function rules(): array
    {
        $companyId = $this->writeCompanyId();

        return [
            'supplier_id'  => ['required', 'uuid', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'purchase_id'  => ['nullable', 'uuid', Rule::exists('purchases', 'id')->where('company_id', $companyId)],
            'payment_date' => ['required', 'date'],
            'amount'       => ['required', 'numeric', 'gt:0'],
            'mode'         => ['required', 'in:cash,bank,upi,cheque'],
            'reference'    => ['nullable', 'string', 'max:100'],
            'notes'        => ['nullable', 'string', 'max:1000'],
            'is_advance'   => ['sometimes', 'boolean'],
            'allocations'                  => ['nullable', 'array'],
            'allocations.*.purchase_id'    => ['required_with:allocations', 'uuid', Rule::exists('purchases', 'id')->where('company_id', $companyId)],
            'allocations.*.amount'         => ['required_with:allocations', 'numeric', 'gt:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $companyId = $this->writeCompanyId();
            $amount = (float) $this->input('amount');
            $supplierId = $this->input('supplier_id');
            $purchaseId = $this->input('purchase_id');

            $supplier = Supplier::forCompany($companyId)->find($supplierId);
            if (! $supplier) {
                return;
            }

            $outstanding = (float) $supplier->outstanding;
            $isAdvance = $this->boolean('is_advance');
            $allocations = collect($this->input('allocations', []))->filter(fn ($r) => (float) ($r['amount'] ?? 0) > 0);

            if ($isAdvance && (filled($purchaseId) || $allocations->isNotEmpty())) {
                $validator->errors()->add('is_advance', 'Record the advance without a GRN, then apply it to invoices.');

                return;
            }

            if ($isAdvance) {
                return;
            }

            $againstOutstanding = $allocations->isNotEmpty()
                ? (float) $allocations->sum(fn ($r) => (float) $r['amount'])
                : $amount;

            if ($againstOutstanding > $outstanding + 1e-6) {
                $validator->errors()->add(
                    'amount',
                    "Payment amount cannot exceed supplier outstanding ({$outstanding}).",
                );
            }

            if ($allocations->isNotEmpty()) {
                foreach ($allocations as $i => $row) {
                    $purchase = Purchase::forCompany($companyId)->find($row['purchase_id'] ?? null);
                    if (! $purchase) {
                        continue;
                    }
                    if ((string) $purchase->supplier_id !== (string) $supplierId) {
                        $validator->errors()->add("allocations.$i.purchase_id", 'Purchase does not belong to the selected supplier.');
                    }
                    $balance = self::remainingPurchaseBalance($purchase);
                    if ((float) $row['amount'] > $balance + 1e-6) {
                        $validator->errors()->add("allocations.$i.amount", "Exceeds remaining GRN balance ({$balance}).");
                    }
                }

                return;
            }

            if (! filled($purchaseId)) {
                return;
            }

            $purchase = Purchase::forCompany($companyId)->find($purchaseId);
            if (! $purchase) {
                return;
            }

            if (! $purchase->isConfirmed()) {
                $validator->errors()->add(
                    'purchase_id',
                    'Payment can only be allocated to a confirmed purchase.',
                );

                return;
            }

            if ((string) $purchase->supplier_id !== (string) $supplierId) {
                $validator->errors()->add(
                    'purchase_id',
                    'Purchase does not belong to the selected supplier.',
                );

                return;
            }

            $balance = self::remainingPurchaseBalance($purchase);
            if ($amount > $balance + 1e-6) {
                $validator->errors()->add(
                    'amount',
                    "Payment amount cannot exceed the remaining GRN balance ({$balance}).",
                );
            }
        });
    }

    /** Company the payment should be written to (party company for super-admin All Companies). */
    public function writeCompanyId(): int|string
    {
        $header = $this->route('current_company')->id;
        if (! $this->user()?->is_super_admin) {
            return $header;
        }

        $supplier = Supplier::query()->find($this->input('supplier_id'));

        return $supplier?->company_id ?? $header;
    }

    /** Mirrors PaymentService payables: invoice total minus payments already linked to this GRN. */
    public static function remainingPurchaseBalance(Purchase $purchase): float
    {
        $paid = (float) SupplierPayment::query()
            ->where('purchase_id', $purchase->id)
            ->sum('amount');
        $paid += (float) SupplierPaymentAllocation::query()
            ->where('purchase_id', $purchase->id)
            ->sum('amount');

        return max(0, round((float) $purchase->grand_total - $paid, 2));
    }
}
