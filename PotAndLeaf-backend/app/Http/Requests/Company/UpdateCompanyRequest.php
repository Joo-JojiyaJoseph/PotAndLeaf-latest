<?php

namespace App\Http\Requests\Company;

class UpdateCompanyRequest extends StoreCompanyRequest
{
    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:150'],
            'legal_name'  => ['nullable', 'string', 'max:200'],
            'gst_number'  => ['nullable', 'string', 'max:20'],
            'state'       => ['nullable', 'string', 'max:60'],
            'state_code'  => ['nullable', 'string', 'max:2'],
            'address'     => ['nullable', 'string', 'max:500'],
            'phone'       => ['nullable', 'string', 'max:20', 'regex:/^(?=.*\d)\+?[0-9()\-\s]{7,20}$/'],
            'email'       => ['nullable', 'email', 'max:150'],
            'logo'        => ['nullable', 'string', 'max:500'],
            'photo'       => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:2000'],
            'locations'   => ['nullable', 'string', 'max:5000'],
            'is_active'   => ['boolean'],
        ];
    }
}