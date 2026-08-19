<?php

namespace App\Http\Requests\Backorder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBackorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('backorder.create', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;

        return [
            'customer_id'   => ['required', 'uuid', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'location_id'   => ['nullable', 'uuid', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'order_date'    => ['required', 'date'],
            'expected_date' => ['nullable', 'date'],
            'notes'         => ['nullable', 'string', 'max:2000'],
            'items'         => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.ordered_qty'  => ['required', 'numeric', 'gt:0'],
            'items.*.rate'         => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
