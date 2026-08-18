<?php

namespace App\Http\Requests\AdvanceOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdvanceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('advance.create', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;

        return [
            'customer_id'    => ['required', 'uuid', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'order_date'     => ['required', 'date'],
            'expected_date'  => ['nullable', 'date', 'after_or_equal:order_date'],
            'advance_amount' => ['nullable', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string', 'max:1000'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.qty'          => ['required', 'numeric', 'gt:0'],
            'items.*.rate'         => ['required', 'numeric', 'min:0'],
            'items.*.gst_rate'     => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
