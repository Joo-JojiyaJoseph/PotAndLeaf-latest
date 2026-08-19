<?php

namespace App\Http\Requests\Sale;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $companyId = $this->route('current_company')->id;
        if (! $this->user()->hasPermission('sales.create', $companyId)) {
            return false;
        }

        $kind = $this->input('bill_kind', 'tax_invoice');
        if ($kind === 'complimentary') {
            return $this->user()->hasPermission('sales.confirm', $companyId);
        }

        return true;
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;

        return [
            'customer_id'   => ['nullable', 'uuid', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'location_id'   => ['nullable', 'uuid', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'sale_date'     => ['required', 'date'],
            'is_interstate' => ['boolean'],
            'payment_mode'  => ['required', 'in:cash,card,upi,credit'],
            'bill_kind'     => ['nullable', 'in:tax_invoice,proforma,complimentary'],
            'amount_paid'               => ['nullable', 'numeric', 'min:0'],
            'loyalty_points_redeemed'   => ['nullable', 'integer', 'min:0'],
            'notes'                     => ['nullable', 'string', 'max:2000'],
            'items'                     => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.product_batch_id' => ['nullable', 'uuid', Rule::exists('product_batches', 'id')->where('company_id', $companyId)],
            'items.*.qty'          => ['required', 'numeric', 'gt:0'],
            'items.*.rate'         => ['required', 'numeric', 'min:0'],
            'items.*.price_level'  => ['nullable', 'in:retail,wholesale,dealer'],
            'items.*.discount'     => ['nullable', 'numeric', 'min:0'],
            'items.*.gst_rate'     => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
