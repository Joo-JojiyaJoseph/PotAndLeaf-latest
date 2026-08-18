<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;
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

        return [
            'supplier_id'   => ['required', 'uuid', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'purchase_date' => ['required', 'date'],
            'invoice_no'    => ['nullable', 'string', 'max:100'],
            'invoice_date'  => ['nullable', 'date'],
            'is_interstate' => ['boolean'],
            'landed_cost_total' => ['nullable', 'numeric', 'min:0'],
            'notes'         => ['nullable', 'string', 'max:2000'],

            'items'                => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.qty'          => ['required', 'numeric', 'gt:0'],
            'items.*.rate'         => ['required', 'numeric', 'min:0'],
            'items.*.discount'     => ['nullable', 'numeric', 'min:0'],
            'items.*.gst_rate'     => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Sellable-as-a-set: only relevant when a line is flagged bulk.
            'items.*.is_bulk'            => ['sometimes', 'boolean'],
            'items.*.sell_as'            => ['nullable', Rule::in(['set_only', 'split_only', 'both'])],
            'items.*.units_per_set'      => [
                'nullable', 'numeric', 'gt:0',
                'required_if:items.*.sell_as,split_only,both',
            ],
            // Left blank, the confirm step auto-provisions a new split/set SKU.
            'items.*.split_product_id'   => ['nullable', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.set_product_id'     => ['nullable', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
        ];
    }
}
