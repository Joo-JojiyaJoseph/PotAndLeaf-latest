<?php

namespace App\Http\Requests\Commission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertCommissionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('commission.manage', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        return [
            'user_id'         => ['required', 'integer', Rule::exists('company_user', 'user_id')->where('company_id', $this->route('current_company')->id)],
            'rate_type'       => ['nullable', Rule::in(['percent', 'per_unit'])],
            'base_percent'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            'per_unit_amount' => ['nullable', 'numeric', 'min:0'],
            'monthly_target'  => ['nullable', 'numeric', 'min:0'],
            'target_bonus'    => ['nullable', 'numeric', 'min:0'],
            'notes'           => ['nullable', 'string', 'max:1000'],
            'is_active'       => ['boolean'],
            'is_supervisor'   => ['boolean'],
            'location_id'     => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $this->route('current_company')->id)],
            'effective_from'  => ['nullable', 'date'],
            'effective_to'    => ['nullable', 'date', 'after_or_equal:effective_from'],
            'max_commission'  => ['nullable', 'numeric', 'min:0'],
            'eligible_bill_kinds' => ['nullable', 'array'],
            'eligible_bill_kinds.*' => [Rule::in(['tax_invoice', 'proforma', 'complimentary'])],
        ];
    }
}
