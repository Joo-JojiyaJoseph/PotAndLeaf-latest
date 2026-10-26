<?php

namespace App\Http\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;
use App\Support\Validation\IndianFormats;
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
        $this->merge(IndianFormats::normalise($this->all()));
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
                'nullable', 'string', 'max:50',
                Rule::unique('suppliers', 'supplier_code')
                    ->where('company_id', $companyId)
                    ->ignore($supplierId),
            ],
            'name'            => [
                'required', 'string', 'max:191',
                Rule::unique('suppliers', 'name')
                    ->where('company_id', $companyId)
                    ->ignore($supplierId),
            ],
            'email'           => ['nullable', 'email', 'max:191'],
            'phone'           => ['nullable', 'string', 'max:20', 'regex:/^(?=.*\d)\+?[0-9()\-\s]{7,20}$/'],
            'gst_number'      => ['nullable', 'string', 'max:15', 'regex:'.IndianFormats::GSTIN,
                IndianFormats::notDuplicateEncrypted(\App\Models\Supplier::class, $companyId, 'gst_number', $supplierId, 'Another supplier in this company already has this GST number.')],
            'pan_number'      => ['nullable', 'string', 'regex:'.IndianFormats::PAN],
            'address_line1'   => ['nullable', 'string', 'max:191'],
            'address_line2'   => ['nullable', 'string', 'max:191'],
            'city'            => ['nullable', 'string', 'max:100'],
            'state'           => ['nullable', 'string', 'max:100'],
            'country'         => ['nullable', 'string', 'max:100'],
            'pincode'         => ['nullable', 'string', 'regex:'.IndianFormats::PINCODE],
            'bank_name'         => ['nullable', 'string', 'max:191'],
            'bank_account_name' => ['nullable', 'string', 'max:191'],
            'bank_account_no'   => ['nullable', 'string', 'max:34'],
            'bank_ifsc'         => ['nullable', 'string', 'max:15', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'address'           => ['required', 'string', 'max:2000'],
            'photo'             => ['nullable', 'string', 'max:500'],
            'credit_limit'      => ['nullable', 'numeric', 'min:0'],
            'credit_days'       => ['nullable', 'integer', 'min:0', 'max:365'],
            'opening_balance'   => ['nullable', 'numeric'],
            'notes'             => ['nullable', 'string', 'max:2000'],
            'status'            => ['required', new Enum(SupplierStatus::class)],
            'company_id'        => [
                'sometimes', 'integer',
                Rule::exists('companies', 'id'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex'          => 'Enter a valid phone number (digits, and optionally + - ( ) spaces).',
            'name.unique'          => 'A supplier with this name already exists in this company.',
            'supplier_code.unique' => 'This supplier code is already used in this company.',
            'gst_number.unique' => 'Another supplier in this company already has this GST number.',
        ] + IndianFormats::MESSAGES;
    }
}