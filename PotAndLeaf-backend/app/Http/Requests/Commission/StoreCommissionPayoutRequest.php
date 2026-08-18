<?php

namespace App\Http\Requests\Commission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommissionPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('commission.pay', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        return [
            'user_id'      => ['required', 'integer', Rule::exists('company_user', 'user_id')->where('company_id', $this->route('current_company')->id)],
            'period'       => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'sales_total'  => ['nullable', 'numeric', 'min:0'],
            'amount'       => ['required', 'numeric', 'min:0'],
            'mode'         => ['required', 'in:cash,bank,upi'],
            'payment_date' => ['nullable', 'date'],
            'reference'    => ['nullable', 'string', 'max:100'],
            'notes'        => ['nullable', 'string', 'max:1000'],
            'status'       => ['required', 'in:draft,paid'],
        ];
    }
}
