<?php

namespace App\Http\Requests\SalesReturn;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('sales_returns.create', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;

        return [
            'sale_id'     => ['required', 'uuid', Rule::exists('sales', 'id')->where('company_id', $companyId)],
            'return_date' => ['required', 'date'],
            'reason'      => ['nullable', 'string', 'max:255'],
            'notes'       => ['nullable', 'string', 'max:2000'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.sale_item_id'  => ['required', 'uuid'],
            'items.*.qty'           => ['required', 'numeric', 'gt:0'],
        ];
    }
}
