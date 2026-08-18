<?php

namespace App\Http\Requests\Rental;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRentalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('rental.create', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;

        return [
            'customer_id'       => ['required', 'uuid', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'location_id'       => ['nullable', 'uuid', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'start_date'        => ['required', 'date'],
            'expected_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'billing_cycle'     => ['required', 'in:daily,weekly,monthly'],
            'deposit'           => ['nullable', 'numeric', 'min:0'],
            'notes'             => ['nullable', 'string', 'max:1000'],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.qty'              => ['required', 'numeric', 'gt:0'],
            'items.*.rate_per_cycle'   => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
