<?php

namespace App\Http\Requests\BulkSplit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBulkSplitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('bulk_splits.create', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;

        return [
            'source_product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'source_qty'        => ['required', 'numeric', 'gt:0'],
            'split_date'        => ['required', 'date'],
            'notes'             => ['nullable', 'string', 'max:2000'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.product_id'    => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.qty'           => ['required', 'numeric', 'gt:0'],
            'items.*.weight'        => ['nullable', 'numeric', 'min:0'],
            'items.*.retail_price'  => ['nullable', 'numeric', 'min:0'],
            'markup_percent'        => ['nullable', 'numeric', 'min:0', 'max:500'],
            'source_purchase_id'    => ['nullable', 'uuid', Rule::exists('purchases', 'id')->where('company_id', $companyId)],
        ];
    }
}
