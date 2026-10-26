<?php

namespace App\Http\Requests\Role;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends StoreRoleRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_super_admin;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('name') && ! $this->filled('slug')) {
            $this->merge(['slug' => Str::slug($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        $roleId = $this->route('role')->id;

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($roleId)],
            'slug' => [
                'required', 'string', 'max:100',
                Rule::unique('roles', 'slug')->ignore($roleId),
            ],
            'description'   => ['nullable', 'string', 'max:500'],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'A role with this name already exists.', 'slug.unique' => 'A role with this name already exists.'];
    }
}
