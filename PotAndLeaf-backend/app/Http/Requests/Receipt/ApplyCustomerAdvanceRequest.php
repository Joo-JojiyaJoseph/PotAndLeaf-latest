<?php

namespace App\Http\Requests\Receipt;

use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ApplyCustomerAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $companyId = $this->writeCompanyId();

        return $this->user()->hasPermission('receipts.create', $companyId)
            || $this->user()->hasPermission('advance.create', $companyId);
    }

    public function rules(): array
    {
        $companyId = $this->writeCompanyId();

        return [
            'customer_id' => ['required', 'uuid', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'sale_id'     => ['required', 'uuid', Rule::exists('sales', 'id')->where('company_id', $companyId)],
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
            $customer = Customer::forCompany($companyId)->find($this->input('customer_id'));
            $sale = Sale::forCompany($companyId)->find($this->input('sale_id'));
            $amount = (float) $this->input('amount');

            if (! $customer || ! $sale) {
                return;
            }

            if ((string) $sale->customer_id !== (string) $customer->id) {
                $validator->errors()->add('sale_id', 'Invoice does not belong to the selected customer.');
            }

            $advance = (float) $customer->advance_balance;
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

        $customer = Customer::query()->find($this->input('customer_id'));

        return $customer?->company_id ?? $header;
    }
}
