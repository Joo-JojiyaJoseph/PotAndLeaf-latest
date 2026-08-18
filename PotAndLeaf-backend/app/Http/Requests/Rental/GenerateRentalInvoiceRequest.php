<?php

namespace App\Http\Requests\Rental;

use Illuminate\Foundation\Http\FormRequest;

class GenerateRentalInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('rental.bill', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        return [
            'period_from' => ['required', 'date'],
            'period_to'   => ['required', 'date', 'after_or_equal:period_from'],
        ];
    }
}
