<?php

namespace App\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertBomRequest extends FormRequest
{
    public function authorize(): bool
    {
        $companyId = $this->route('current_company')->id;
        $user = $this->user();

        if (! $user->hasPermission('production.manage_bom', $companyId)) {
            return false;
        }

        if ($this->filled('new_product') && ! $user->hasPermission('products.create', $companyId) && ! $user->hasPermission('*', $companyId)) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;
        $prod = fn () => Rule::exists('products', 'id')->where('company_id', $companyId);
        $itemRules = [
            'items.*.component_product_id' => ['required', 'uuid', $prod()],
            'items.*.qty'                  => ['required', 'numeric', 'gt:0'],
            'items.*.wastage_pct'          => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
        $stageItemRules = [
            'stages.*.items.*.component_product_id' => ['required', 'uuid', $prod()],
            'stages.*.items.*.qty'                  => ['required', 'numeric', 'gt:0'],
            'stages.*.items.*.wastage_pct'          => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];

        return [
            'product_id' => ['required_without:new_product', 'nullable', 'uuid', $prod()],
            'new_product' => ['required_without:product_id', 'nullable', 'array'],
            'new_product.sku' => [
                'required_with:new_product', 'string', 'max:50',
                Rule::unique('products', 'sku')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'new_product.name' => ['required_with:new_product', 'string', 'max:191'],
            'new_product.unit_id' => ['nullable', 'uuid', Rule::exists('product_units', 'id')->where('company_id', $companyId)],
            'name'       => ['required', 'string', 'max:150'],
            'output_qty' => ['required', 'numeric', 'gt:0'],
            'is_active'  => ['boolean'],
            'notes'      => ['nullable', 'string', 'max:1000'],
            'items'                        => ['required_without:stages', 'array', 'min:1'],
            ...$itemRules,
            'stages'                       => ['required_without:items', 'array', 'min:2'],
            'stages.*.name'                => ['required', 'string', 'max:150'],
            'stages.*.notes'               => ['nullable', 'string', 'max:1000'],
            'stages.*.items'               => ['required', 'array', 'min:1'],
            ...$stageItemRules,
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required_without' => 'Select an existing output product or fill in the new product details.',
            'new_product.required_without' => 'Select an existing output product or fill in the new product details.',
            'stages.min' => 'Multi-stage recipes need at least two stages.',
        ];
    }
}
