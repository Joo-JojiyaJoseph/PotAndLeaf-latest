<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
}
