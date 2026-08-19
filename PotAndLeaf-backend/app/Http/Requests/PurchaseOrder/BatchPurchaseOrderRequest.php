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
            'expected_date' => ['nullable', 'date'],
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
}
