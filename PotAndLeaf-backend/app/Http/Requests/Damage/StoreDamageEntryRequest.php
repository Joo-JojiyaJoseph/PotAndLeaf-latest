<?php

namespace App\Http\Requests\Damage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDamageEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('damage.create', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;

        return [
            'product_id'  => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'product_batch_id' => ['nullable', 'uuid', Rule::exists('product_batches', 'id')->where('company_id', $companyId)],
            'location_id' => ['nullable', 'uuid', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'qty'         => ['required', 'numeric', 'gt:0'],
            'reason'      => ['required', 'string', 'max:120'],
            'notes'       => ['nullable', 'string', 'max:2000'],
            'photo'       => ['nullable', 'string', 'max:500'],
            'entry_date'  => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'Please select a product.',
            'reason.required'     => 'Please provide a damage reason.',
            'qty.required'        => 'Please enter the damaged quantity.',
            'qty.gt'              => 'Quantity must be greater than zero.',
        ];
    }
}
