<?php

namespace App\Http\Requests\Company;

class UpdateCompanyRequest extends StoreCompanyRequest
{
    public function rules(): array
    {
        // Same checks as Add, but the company being edited may keep its own name / GST number.
        return $this->sharedRules($this->route('company')?->id);
    }
}
