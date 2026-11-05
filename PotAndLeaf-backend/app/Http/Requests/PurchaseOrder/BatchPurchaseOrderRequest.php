<?php

namespace App\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BatchPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('po.create', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;

        return [
            'po_date'       => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:po_date', $this->notPast()],
            'notes'         => ['nullable', 'string', 'max:2000'],
            'orders'        => ['required', 'array', 'min:1'],
            'orders.*.supplier_id' => ['required', 'uuid', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'orders.*.items'       => ['required', 'array', 'min:1'],
            'orders.*.items.*.product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'orders.*.items.*.qty'        => ['required', 'numeric', 'gt:0'],
            'orders.*.items.*.rate'       => ['required', 'numeric', 'min:0'],
            'orders.*.items.*.gst_rate'   => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** A date that looks ahead (expected / start) cannot be a day before today on a new record. */
    protected function notPast(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if ($this->isMethod('POST') && filled($value) && strtotime((string) $value) !== false
                && date('Y-m-d', strtotime((string) $value)) < now()->toDateString()) {
                $fail('Choose today or a later date.');
            }
        };
    }
}
