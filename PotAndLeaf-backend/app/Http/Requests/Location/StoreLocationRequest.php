<?php

namespace App\Http\Requests\Location;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('locations.manage', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;
        $id = $this->route('location')?->id;

        return [
            'name'       => ['required', 'string', 'max:120'],
            'code'       => ['required', 'string', 'max:30', Rule::unique('locations', 'code')->where('company_id', $companyId)->ignore($id)],
            'type'       => ['required', 'in:godown,shop'],
            'is_default' => ['boolean'],
            'is_active'  => ['boolean'],
        ];
    }
}
