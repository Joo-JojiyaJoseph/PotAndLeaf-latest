<?php

namespace App\Http\Requests\Sale;

use Illuminate\Foundation\Http\FormRequest;

class RequestSaleCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('sales.cancel_request', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
