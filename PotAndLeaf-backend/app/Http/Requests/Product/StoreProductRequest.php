<?php

namespace App\Http\Requests\Product;

use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $companyId = $this->route('current_company')?->id;

        return $this->user()->hasPermission('products.create', $companyId);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;

        return array_merge($this->baseRules($companyId, null), [
            'sku' => [
                'nullable', 'string', 'max:50',
                Rule::unique('products', 'sku')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
        ]);
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'name.unique'   => 'A product with this name already exists in this company.',
            'sku.unique'    => 'This SKU is already used by another product in this company.',
        ];
    }

    /** Shared field rules, reused by the update request. */
    protected function baseRules(int|string $companyId, ?string $ignoreId): array
    {
        return [
            'name'            => [
                'required', 'string', 'max:191',
                Rule::unique('products', 'name')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($ignoreId),
            ],
            'barcode'         => ['nullable', 'string', 'max:100'],
            'hsn_code'        => ['nullable', 'string', 'max:20'],
            'description'     => ['nullable', 'string', 'max:2000'],
            'category_id'     => ['nullable', 'uuid', Rule::exists('product_categories', 'id')->where('company_id', $companyId)],
            'brand_id'        => ['nullable', 'uuid', Rule::exists('product_brands', 'id')->where('company_id', $companyId)],
            'unit_id'         => ['nullable', 'uuid', Rule::exists('product_units', 'id')->where('company_id', $companyId)],
            'gst_rate'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'mrp'             => ['nullable', 'numeric', 'min:0'],
            'cost_price'      => ['nullable', 'numeric', 'min:0'],
            'dealer_price'    => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'retail_price'    => ['nullable', 'numeric', 'min:0'],
            'length_cm'       => ['nullable', 'numeric', 'min:0'],
            'width_cm'        => ['nullable', 'numeric', 'min:0'],
            'height_cm'       => ['nullable', 'numeric', 'min:0'],
            'reorder_level'   => ['nullable', 'numeric', 'min:0'],
            'opening_stock'   => ['nullable', 'numeric', 'min:0'],
            'images'          => ['nullable', 'array'],
            'images.*'        => ['string'],
            'status'          => ['required', new Enum(ProductStatus::class)],
            'is_rental'       => ['sometimes', 'boolean'],
            'rental_daily_rate' => ['nullable', 'numeric', 'min:0'],

            'suppliers'                 => ['nullable', 'array'],
            'suppliers.*.supplier_id'   => ['required', 'uuid', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'suppliers.*.supplier_price' => ['nullable', 'numeric', 'min:0'],
            'suppliers.*.is_primary'    => ['nullable', 'boolean'],
        ];
    }
}
