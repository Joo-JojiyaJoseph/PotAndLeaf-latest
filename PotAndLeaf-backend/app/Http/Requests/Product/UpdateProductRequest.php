<?php

namespace App\Http\Requests\Product;

class UpdateProductRequest extends StoreProductRequest
{
    public function authorize(): bool
    {
        $companyId = $this->route('current_company')?->id;

        return $this->user()->hasPermission('products.update', $companyId);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;
        $productId = $this->route('product')->id;

        return array_merge($this->baseRules($companyId, $productId), [
            // SKU is immutable — not accepted on update (see UpdateProduct action).
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'category_id.exists' => 'Selected category does not belong to this company.',
            'unit_id.exists'     => 'Selected unit does not belong to this company.',
        ]);
    }
}
