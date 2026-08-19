<?php

namespace App\Http\Requests\Backorder;

use Illuminate\Foundation\Http\FormRequest;

class FulfillBackorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('backorder.fulfill', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        return [
            'items'       => ['required', 'array', 'min:1'],
            'items.*.id'  => ['required', 'uuid'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
