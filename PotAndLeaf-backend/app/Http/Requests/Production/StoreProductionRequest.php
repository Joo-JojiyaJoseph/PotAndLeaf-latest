<?php

namespace App\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $companyId = $this->route('current_company')->id;
        $user = $this->user();

        if (! $user->hasPermission('production.create', $companyId)) {
            return false;
        }

        if ($this->filled('new_product') && ! $user->hasPermission('products.create', $companyId) && ! $user->is_super_admin) {
            return false;
        }

        if ($this->boolean('complete') && ! $user->hasPermission('production.complete', $companyId)) {
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
        ];
        $stageItemRules = [
            'stages.*.items.*.component_product_id' => ['required', 'uuid', $prod()],
            'stages.*.items.*.qty'                  => ['required', 'numeric', 'gt:0'],
        ];

        return [
            'product_id' => ['required_without:new_product', 'nullable', 'uuid', $prod()],
            'new_product' => ['required_without:product_id', 'nullable', 'array'],
            'new_product.name' => ['required_with:new_product', 'string', 'max:191'],
            'new_product.unit_id' => ['nullable', 'uuid', Rule::exists('product_units', 'id')->where('company_id', $companyId)],
            // The same fields as the product form: category, GST and every price.
            'new_product.category_id'     => ['nullable', 'uuid', Rule::exists('product_categories', 'id')->where('company_id', $companyId)],
            'new_product.gst_rate'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'new_product.mrp'             => ['nullable', 'numeric', 'min:0'],
            'new_product.retail_price'    => ['nullable', 'numeric', 'min:0'],
            'new_product.wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'new_product.dealer_price'    => ['nullable', 'numeric', 'min:0'],
            // Prices to save on an existing product (only when update_prices is ticked).
            'update_prices'               => ['sometimes', 'boolean'],
            'prices'                      => ['nullable', 'array'],
            'prices.gst_rate'             => ['nullable', 'numeric', 'min:0', 'max:100'],
            'prices.mrp'                  => ['nullable', 'numeric', 'min:0'],
            'prices.retail_price'         => ['nullable', 'numeric', 'min:0'],
            'prices.wholesale_price'      => ['nullable', 'numeric', 'min:0'],
            'prices.dealer_price'         => ['nullable', 'numeric', 'min:0'],
            // Costs of the run besides the materials, and the margin used to suggest a price.
            'setting_charge'              => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'misc_charge'                 => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'margin_type'                 => ['nullable', 'in:percent,amount'],
            'margin_value'                => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'output_quantity' => ['required', 'numeric', 'gt:0'],
            'supervisor_id'   => ['required', 'integer', Rule::exists('users', 'id')],
            'order_date'      => ['required', 'date'],
            'notes'           => ['nullable', 'string', 'max:1000'],
            'complete'        => ['sometimes', 'boolean'],
            'items'           => ['required_without:stages', 'array', 'min:1'],
            ...$itemRules,
            'stages'          => ['required_without:items', 'array', 'min:2'],
            'stages.*.name'   => ['required', 'string', 'max:150'],
            'stages.*.items'  => ['required', 'array', 'min:1'],
            ...$stageItemRules,
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required_without' => 'Select the product to produce.',
            'new_product.required_without' => 'Select the product to produce or create a new one.',
            'stages.min' => 'Multi-stage production needs at least two stages.',
        ];
    }
}
