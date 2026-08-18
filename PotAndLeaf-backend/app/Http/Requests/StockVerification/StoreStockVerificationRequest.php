<?php

namespace App\Http\Requests\StockVerification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('stock_verifications.create', $this->companyId());
    }

    protected function companyId(): int|string
    {
        return $this->route('current_company')->id;
    }

    public function rules(): array
    {
        $companyId = $this->companyId();

        return [
            'count_date'    => ['required', 'date'],
            'location_note' => ['nullable', 'string', 'max:120'],
            'notes'         => ['nullable', 'string', 'max:2000'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.product_id'    => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.counted_qty'   => ['required', 'numeric', 'min:0'],
        ];
    }
}
