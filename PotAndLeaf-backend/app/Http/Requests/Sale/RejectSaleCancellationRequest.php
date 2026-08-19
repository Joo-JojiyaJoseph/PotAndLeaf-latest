<?php

namespace App\Http\Requests\Sale;

use Illuminate\Foundation\Http\FormRequest;

class RejectSaleCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('sales.cancel_approve', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
