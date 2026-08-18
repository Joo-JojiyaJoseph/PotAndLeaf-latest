<?php

namespace App\Http\Requests\Transfer;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('transfers.receive', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        return [
            'receipts'                 => ['nullable', 'array'],
            'receipts.*.id'            => ['required', 'uuid'],
            'receipts.*.received_qty'  => ['required', 'numeric', 'min:0'],
        ];
    }
}
