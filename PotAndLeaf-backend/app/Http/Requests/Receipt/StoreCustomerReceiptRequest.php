<?php

namespace App\Http\Requests\Receipt;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('receipts.create', $this->writeCompanyId());
    }

    public function rules(): array
    {
        $companyId = $this->writeCompanyId();

        return [
            'customer_id'  => ['required', 'uuid', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'sale_id'      => ['nullable', 'uuid', Rule::exists('sales', 'id')->where('company_id', $companyId)],
            'receipt_date' => ['required', 'date'],
            'amount'       => ['required', 'numeric', 'gt:0'],
            'mode'         => ['required', 'in:cash,bank,upi,cheque,card'],
            'reference'    => ['nullable', 'string', 'max:100'],
            'notes'        => ['nullable', 'string', 'max:1000'],
            'is_advance'   => ['sometimes', 'boolean'],
            'allocations'              => ['nullable', 'array'],
            'allocations.*.sale_id'    => ['required_with:allocations', 'uuid', Rule::exists('sales', 'id')->where('company_id', $companyId)],
            'allocations.*.amount'     => ['required_with:allocations', 'numeric', 'gt:0'],
        ];
    }

    /** Company the receipt should be written to (party company for super-admin All Companies). */
    public function writeCompanyId(): int|string
    {
        $header = $this->route('current_company')->id;
        if (! $this->user()?->is_super_admin) {
            return $header;
        }

        $customer = Customer::query()->find($this->input('customer_id'));

        return $customer?->company_id ?? $header;
    }
}
