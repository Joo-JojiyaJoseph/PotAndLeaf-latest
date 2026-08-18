<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_super_admin;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:150'],
            'code'        => ['nullable', 'string', 'max:30', Rule::unique('companies', 'code')->whereNull('deleted_at')],
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

    protected function prepareForValidation(): void
    {
        if ($this->filled('photo') && ! $this->filled('logo')) {
            $this->merge(['logo' => $this->input('photo')]);
        }

        // Accept `locations` as either a newline-delimited string or an array
        // of rows (dynamic form inputs). Normalize to a newline string so the
        // `string` rule passes and the controller sees one canonical shape.
        if (is_array($this->input('locations'))) {
            $this->merge([
                'locations' => collect($this->input('locations'))
                    ->map(fn ($l) => is_string($l) ? trim($l) : '')
                    ->filter()
                    ->implode("\n"),
            ]);
        }
    }
}