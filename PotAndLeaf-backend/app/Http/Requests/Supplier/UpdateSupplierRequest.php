<?php

namespace App\Http\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use App\Enums\SupplierStatus;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('supplier'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('bank_ifsc') && blank($this->input('bank_ifsc'))) {
            $this->merge(['bank_ifsc' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = $this->route('current_company')?->id ?? $this->user()->current_company_id;
        $supplierId = $this->route('supplier')->id;

        return [
            'supplier_code' => [
                'required', 'string', 'max:50',
                Rule::unique('suppliers', 'supplier_code')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->ignore($supplierId),
            ],
            'name'            => ['required', 'string', 'max:191'],
            'email'           => ['nullable', 'email', 'max:191'],
            'phone'           => ['nullable', 'string', 'max:20', 'regex:/^(?=.*\d)\+?[0-9()\-\s]{7,20}$/'],
            'gst_number'      => ['nullable', 'string', 'max:20'],
            'pan_number'      => ['nullable', 'string', 'max:15'],
            'address_line1'   => ['nullable', 'string', 'max:191'],
            'address_line2'   => ['nullable', 'string', 'max:191'],
            'city'            => ['nullable', 'string', 'max:100'],
            'state'           => ['nullable', 'string', 'max:100'],
            'country'         => ['nullable', 'string', 'max:100'],
            'pincode'         => ['nullable', 'string', 'max:12'],
            'bank_name'         => ['nullable', 'string', 'max:191'],
            'bank_account_name' => ['nullable', 'string', 'max:191'],
            'bank_account_no'   => ['nullable', 'string', 'max:34'],
            'bank_ifsc'         => ['nullable', 'string', 'max:15', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'address'           => ['required', 'string', 'max:2000'],
            'photo'             => ['nullable', 'string', 'max:500'],
            'credit_days'       => ['nullable', 'integer', 'min:0', 'max:3650'],
            'credit_limit'      => ['nullable', 'numeric', 'min:0'],
            'opening_balance'   => ['nullable', 'numeric'],
            'notes'             => ['nullable', 'string', 'max:2000'],
            'status'            => ['required', new Enum(SupplierStatus::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number (digits, and optionally + - ( ) spaces).',
        ];
    }
}
