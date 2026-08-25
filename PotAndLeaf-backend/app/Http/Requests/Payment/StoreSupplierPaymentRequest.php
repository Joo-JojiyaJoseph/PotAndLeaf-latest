<?php

namespace App\Http\Requests\Payment;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('payments.create', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;

        return [
            'supplier_id'  => ['required', 'uuid', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'purchase_id'  => ['nullable', 'uuid', Rule::exists('purchases', 'id')->where('company_id', $companyId)],
            'payment_date' => ['required', 'date'],
            'amount'       => ['required', 'numeric', 'gt:0'],
            'mode'         => ['required', 'in:cash,bank,upi,cheque'],
            'reference'    => ['nullable', 'string', 'max:100'],
            'notes'        => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $companyId = $this->route('current_company')->id;
            $amount = (float) $this->input('amount');
            $supplierId = $this->input('supplier_id');
            $purchaseId = $this->input('purchase_id');

            $supplier = Supplier::forCompany($companyId)->find($supplierId);
            if (! $supplier) {
                return;
            }

            $outstanding = (float) $supplier->outstanding;
            if ($amount > $outstanding + 1e-6) {
                $validator->errors()->add(
                    'amount',
                    "Payment amount cannot exceed supplier outstanding ({$outstanding}).",
                );
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

    /** Mirrors PaymentService payables: invoice total minus payments already linked to this GRN. */
    public static function remainingPurchaseBalance(Purchase $purchase): float
    {
        $paid = (float) SupplierPayment::query()
            ->where('purchase_id', $purchase->id)
            ->sum('amount');

        return max(0, round((float) $purchase->grand_total - $paid, 2));
    }
}
