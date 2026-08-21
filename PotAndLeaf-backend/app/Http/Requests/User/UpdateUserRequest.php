<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('users.update', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;

        return [
            'name'      => ['required', 'string', 'max:150'],
            'email'     => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($this->route('user')->id)],
            'password'  => ['nullable', 'string', 'min:8'],
            'phone'     => ['nullable', 'string', 'max:20', 'regex:/^(?=.*\d)\+?[0-9()\-\s]{7,20}$/'],
            'role_id'   => ['nullable', 'uuid', Rule::exists('roles', 'id')],
            'is_active' => ['boolean'],
            'target_company_id' => [
                Rule::prohibitedIf(fn () => ! $this->user()->is_super_admin),
                'nullable', 'integer', Rule::exists('companies', 'id'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number (digits, and optionally + - ( ) spaces).',
        ];
    }
}
