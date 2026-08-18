<?php

namespace App\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('production.create', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;

        return [
            'bom_id'          => ['required', 'uuid', Rule::exists('boms', 'id')->where('company_id', $companyId)],
            'output_quantity' => ['required', 'numeric', 'gt:0'],
            'location_id'     => ['nullable', 'uuid', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'supervisor_id'   => ['nullable', 'integer', Rule::exists('users', 'id')],
            'order_date'      => ['required', 'date'],
            'notes'           => ['nullable', 'string', 'max:1000'],
        ];
    }
}
