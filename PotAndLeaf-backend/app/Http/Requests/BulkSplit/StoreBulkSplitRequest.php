<?php

namespace App\Http\Requests\BulkSplit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBulkSplitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('bulk_splits.create', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;
        $autoCreate = $this->boolean('auto_create_products', true);

        $rules = [
            'source_product_id'     => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'source_qty'            => ['required', 'numeric', 'gt:0'],
            'split_date'            => ['required', 'date'],
            'notes'                 => ['nullable', 'string', 'max:2000'],
            'split_mode'            => ['nullable', 'in:qty_per_split,num_splits,manual'],
            'split_param'           => ['nullable', 'numeric', 'gt:0'],
            'auto_create_products'  => ['nullable', 'boolean'],
            'confirm_immediately'   => ['nullable', 'boolean'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.qty'           => ['required', 'numeric', 'gt:0'],
            'items.*.weight'        => ['nullable', 'numeric', 'min:0'],
            'items.*.retail_price'  => ['nullable', 'numeric', 'min:0'],
            'items.*.split_label'   => ['nullable', 'string', 'max:100'],
            'markup_percent'        => ['nullable', 'numeric', 'min:0', 'max:500'],
            'source_purchase_id'    => ['nullable', 'uuid', Rule::exists('purchases', 'id')->where('company_id', $companyId)],
        ];

        if (! $autoCreate) {
            $rules['items.*.product_id'] = ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)];
        } else {
            $rules['items.*.product_id'] = ['nullable', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $sourceQty = (float) $this->input('source_qty');
            $items = collect($this->input('items', []));
            $totalSplitQty = $items->sum(fn ($item) => (float) ($item['qty'] ?? 0));

            if ($totalSplitQty <= 0) {
                $validator->errors()->add('items', 'Total split quantity must be greater than zero.');
            }

            if ($totalSplitQty > $sourceQty) {
                $validator->errors()->add(
                    'items',
                    'Total split quantity cannot exceed the available bulk quantity.',
                );
            }

            $sourceId = $this->input('source_product_id');
            $source = $sourceId
                ? \App\Models\Product::forCompany($this->route('current_company')->id)->find($sourceId)
                : null;
            if ($source && $sourceQty > (float) $source->current_stock) {
                $validator->errors()->add(
                    'source_qty',
                    'Available quantity cannot exceed stock on hand ('.$source->current_stock.').',
                );
            }

            foreach ($items as $i => $item) {
                if ((float) ($item['qty'] ?? 0) <= 0) {
                    $validator->errors()->add("items.{$i}.qty", 'Each split quantity must be greater than zero.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'source_qty.required' => 'Available bulk quantity is required.',
            'source_qty.gt'       => 'Available bulk quantity must be greater than zero.',
            'items.required'      => 'Add at least one split row.',
            'items.min'           => 'Add at least one split row.',
            'items.*.qty.required' => 'Each split needs a quantity.',
            'items.*.qty.gt'       => 'Each split quantity must be greater than zero.',
        ];
    }
}
