<?php

namespace App\Http\Requests\Transfer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('transfers.create', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;
        $isIntra = $this->input('transfer_type') === 'intra_company';
        $loc = fn () => Rule::exists('locations', 'id')->where('company_id', $companyId)->where('is_active', true);

        return [
            'transfer_type'        => ['nullable', Rule::in(['inter_company', 'intra_company'])],
            'to_company_id'        => [$isIntra ? 'nullable' : 'required', 'integer', Rule::exists('companies', 'id')->where('is_active', true), Rule::notIn([(int) $companyId])],
            'from_location_id'     => [$isIntra ? 'required' : 'nullable', 'uuid', $loc()],
            'to_location_id'       => [$isIntra ? 'required' : 'nullable', 'uuid', $loc(), Rule::notIn([$this->input('from_location_id')])],
            'transfer_date'        => ['required', 'date'],
            'notes'                => ['nullable', 'string', 'max:1000'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.product_batch_id' => ['nullable', 'uuid', Rule::exists('product_batches', 'id')->where('company_id', $companyId)],
            'items.*.qty'          => ['required', 'numeric', 'gt:0'],
        ];
    }
}
