<?php

namespace App\Http\Requests\Product;

use Illuminate\Validation\Rule;

class UpdateProductRequest extends StoreProductRequest
{
    public function authorize(): bool
    {
        $companyId = $this->route('current_company')?->id;

        return $this->user()->hasPermission('products.update', $companyId);
    }

    public function rules(): array
    {
        $headerCompanyId = $this->route('current_company')->id;
        $productId = $this->route('product')->id;
        $targetCompanyId = ($this->user()->is_super_admin && $this->filled('company_id'))
            ? $this->input('company_id')
            : $headerCompanyId;

        return array_merge($this->baseRules($targetCompanyId, $productId), [
            'company_id' => [
                Rule::prohibitedIf(fn () => ! $this->user()->is_super_admin),
                'sometimes', 'integer', Rule::exists('companies', 'id'),
            ],
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
