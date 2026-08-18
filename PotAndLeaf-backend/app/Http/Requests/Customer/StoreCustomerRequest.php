<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('customers.create', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;
        $customerId = optional($this->route('customer'))->id;

        return [
            'customer_code'   => ['nullable', 'string', 'max:40',
                Rule::unique('customers', 'customer_code')->where('company_id', $companyId)->ignore($customerId)],
            'name'            => [
                'required', 'string', 'max:150',
                Rule::unique('customers', 'name')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($customerId),
            ],
            'type'            => ['required', 'in:retail,wholesale,dealer'],
            'email'           => ['nullable', 'email', 'max:150'],
            'phone'           => ['nullable', 'string', 'max:20', 'regex:/^(?=.*\d)\+?[0-9()\-\s]{7,20}$/'],
            'whatsapp'        =>['nullable', 'string', 'max:20', 'regex:/^(?=.*\d)\+?[0-9()\-\s]{7,20}$/'],
            'gst_number'      => ['nullable', 'string', 'max:20'],
            'address_line1'   => ['nullable', 'string', 'max:200'],
            'address_line2'   => ['nullable', 'string', 'max:200'],
            'city'            => ['nullable', 'string', 'max:80'],
            'state'           => ['nullable', 'string', 'max:80'],
            'pincode'         => ['nullable', 'string', 'max:12'],
            'credit_days'     => ['nullable', 'integer', 'min:0'],
            'credit_limit'    => ['nullable', 'numeric', 'min:0'],
            'opening_balance' => ['nullable', 'numeric'],
            'notes'           => ['nullable', 'string', 'max:2000'],
            'status'          => ['required', 'in:active,inactive,blocked'],
            'photo'           => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_code.unique' => 'This customer code is already used by another customer in this company.',
            'name.unique'          => 'A customer with this name already exists in this company.',
        ];
    }
}
