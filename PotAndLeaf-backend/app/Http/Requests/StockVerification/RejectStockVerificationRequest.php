<?php

namespace App\Http\Requests\StockVerification;

use Illuminate\Foundation\Http\FormRequest;

class RejectStockVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('stock_verifications.approve', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
