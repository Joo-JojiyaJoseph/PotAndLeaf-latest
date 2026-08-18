<?php

namespace App\Http\Requests\Customer;

class UpdateCustomerRequest extends StoreCustomerRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('customers.update', $this->route('current_company')->id);
    }
}
