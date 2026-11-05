<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('users.create', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;

        return [
            'name'      => ['required', 'string', 'max:150'],
            'email'     => ['required', ...\App\Support\Validation\Email::rules(), 'max:150', Rule::unique('users', 'email')],
            'password'  => ['required', 'string', 'min:8'],
            'phone'     => ['nullable', 'string', 'max:20', 'regex:/^(?=.*\d)\+?[0-9()\-\s]{7,20}$/'],
            // Every new user needs a role (what they may see and do).
            'role_id'   => ['required', 'uuid', Rule::exists('roles', 'id')],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number (digits, and optionally + - ( ) spaces).',
            'email.email' => \App\Support\Validation\Email::MESSAGE,
            'email.regex' => \App\Support\Validation\Email::MESSAGE,
            'role_id.required' => 'Choose a role for this user. A user without a role cannot do anything.',
        ];
    }
}
