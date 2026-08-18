<?php

namespace App\Http\Requests\PurchaseReturn;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('purchase_returns.create', $this->teamId());
    }

    protected function teamId(): int|string
    {
        return $this->route('current_company')->id;
    }

    public function rules(): array
    {
        $companyId = $this->teamId();

        return [
            'purchase_id' => ['required', 'uuid', Rule::exists('purchases', 'id')->where('company_id', $companyId)],
            'return_date' => ['required', 'date'],
            'reason'      => ['nullable', 'string', 'max:255'],
            'notes'       => ['nullable', 'string', 'max:2000'],

            'items'                      => ['required', 'array', 'min:1'],
            'items.*.purchase_item_id'   => ['required', 'uuid'],
            'items.*.qty'                => ['required', 'numeric', 'gt:0'],
        ];
    }
}
