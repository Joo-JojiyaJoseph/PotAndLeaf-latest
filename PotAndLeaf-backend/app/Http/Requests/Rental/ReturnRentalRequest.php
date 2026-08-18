<?php

namespace App\Http\Requests\Rental;

use Illuminate\Foundation\Http\FormRequest;

class ReturnRentalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('rental.return', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        return [
            'returns'         => ['nullable', 'array'],
            'returns.*.id'    => ['required', 'uuid'],
            'returns.*.qty'   => ['required', 'numeric', 'min:0'],
        ];
    }
}
