<?php

namespace App\Http\Requests\Receipt;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('receipts.create', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;

        return [
            'customer_id'  => ['required', 'uuid', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'sale_id'      => ['nullable', 'uuid', Rule::exists('sales', 'id')->where('company_id', $companyId)],
            'receipt_date' => ['required', 'date'],
            'amount'       => ['required', 'numeric', 'gt:0'],
            'mode'         => ['required', 'in:cash,bank,upi,cheque,card'],
            'reference'    => ['nullable', 'string', 'max:100'],
            'notes'        => ['nullable', 'string', 'max:1000'],
        ];
    }
}
