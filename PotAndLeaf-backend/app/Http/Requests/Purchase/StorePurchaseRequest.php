<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;
use App\Support\Validation\BusinessRules;
use Illuminate\Validation\Rule;

class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('purchases.create', $this->teamId());
    }

    protected function teamId(): int|string
    {
        return $this->route('current_company')->id;
    }

    public function rules(): array
    {
        $companyId = $this->teamId();
        // A super admin may enter the bill for another company.
        $billCompanyId = ($this->user()->is_super_admin && $this->filled('company_id')) ? $this->input('company_id') : $companyId;
        $purchaseId = $this->route('purchase')?->id;

        return [
            'supplier_id'   => [
                'required', 'uuid',
                Rule::exists('suppliers', 'id')
                    ->where('company_id', $companyId)
                    ->where('status', 'active'),
            ],
            'purchase_date' => ['required', 'date', BusinessRules::notFuture('Purchase date')],
            // The same supplier bill must not be entered twice.
            'invoice_no'    => ['nullable', 'string', 'max:100',
                Rule::unique('purchases', 'invoice_no')
                    ->where('company_id', $billCompanyId)
                    ->where('supplier_id', $this->input('supplier_id'))
                    ->where(fn ($q) => $q->where('status', '!=', 'cancelled'))
                    ->ignore($purchaseId)],
            'invoice_date'  => ['nullable', 'date', BusinessRules::notFuture('Invoice date')],
            'is_interstate' => ['boolean'],
            'landed_cost_total' => ['nullable', 'numeric', 'min:0'],
            'notes'         => ['nullable', 'string', 'max:2000'],
            'company_id'    => [
                'sometimes', 'integer',
                Rule::exists('companies', 'id'),
            ],

            'items'                => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.qty'          => ['required', 'numeric', 'gt:0'],
            'items.*.rate'         => ['required', 'numeric', 'min:0'],
            'items.*.discount'     => ['nullable', 'numeric', 'min:0', BusinessRules::discountNotAboveLine(fn ($k) => $this->input($k))],
            'items.*.gst_rate'     => ['nullable', 'numeric', 'min:0', 'max:100'],

            'items.*.is_bulk'            => ['sometimes', 'boolean'],
            'items.*.sell_as'            => ['nullable', Rule::in(['set_only', 'split_only', 'both'])],
            'items.*.units_per_set'      => [
                'nullable', 'numeric', 'gt:0',
                'required_if:items.*.sell_as,split_only,both',
            ],
            'items.*.split_product_id'   => ['nullable', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.set_product_id'     => ['nullable', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Supplier is required.',
            'supplier_id.exists'   => 'Supplier is required.',
            'purchase_date.required' => 'Purchase date is required.',
            'purchase_date.date'     => 'Purchase date is required.',
            'items.required'         => 'At least one product line is required.',
            'items.min'              => 'At least one product line is required.',
            'items.*.product_id.required' => 'Product is required.',
            'items.*.product_id.exists'   => 'Product is required.',
            'items.*.qty.required'        => 'Quantity is required.',
            'items.*.qty.gt'              => 'Quantity is required.',
            'items.*.rate.required'       => 'Rate is required.',
            'invoice_no.unique'           => 'This supplier invoice number is already entered for this supplier. Check you are not entering the same bill twice.',
        ];
    }
}
