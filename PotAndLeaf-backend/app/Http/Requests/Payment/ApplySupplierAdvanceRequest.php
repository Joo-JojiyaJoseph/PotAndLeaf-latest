<?php

namespace App\Http\Requests\Payment;

use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ApplySupplierAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $companyId = $this->writeCompanyId();

        return $this->user()->hasPermission('payments.create', $companyId)
            || $this->user()->hasPermission('advance.create', $companyId);
    }

    public function rules(): array
    {
        $companyId = $this->writeCompanyId();

        return [
            'supplier_id' => ['required', 'uuid', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'purchase_id' => ['required', 'uuid', Rule::exists('purchases', 'id')->where('company_id', $companyId)],
            'amount'      => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $companyId = $this->writeCompanyId();
            $supplier = Supplier::forCompany($companyId)->find($this->input('supplier_id'));
            $purchase = Purchase::forCompany($companyId)->find($this->input('purchase_id'));
            $amount = (float) $this->input('amount');

            if (! $supplier || ! $purchase) {
                return;
            }

            if ((string) $purchase->supplier_id !== (string) $supplier->id) {
                $validator->errors()->add('purchase_id', 'Purchase does not belong to the selected supplier.');
            }

            $advance = (float) $supplier->advance_balance;
            if ($amount > $advance + 1e-6) {
                $validator->errors()->add('amount', "Amount cannot exceed available advance ({$advance}).");
            }
        });
    }

    /** Company the application should be written to (party company for super-admin All Companies). */
    public function writeCompanyId(): int|string
    {
        $header = $this->route('current_company')->id;
        if (! $this->user()?->is_super_admin) {
            return $header;
        }

        $supplier = Supplier::query()->find($this->input('supplier_id'));

        return $supplier?->company_id ?? $header;
    }
}
