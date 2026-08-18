<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Support\Media\MediaStorage;

class UpdateCustomer
{
    public function __construct(private readonly CustomerRepositoryInterface $customers) {}

    /** @param array<string,mixed> $data */
    public function handle(Customer $customer, array $data): Customer
    {
        // "Code (auto if blank)" — never overwrite the existing code with null.
        if (array_key_exists('customer_code', $data) && blank($data['customer_code'])) {
            unset($data['customer_code']);
        }
        foreach (['credit_days', 'credit_limit', 'opening_balance', 'outstanding', 'loyalty_points'] as $k) {
            if (array_key_exists($k, $data) && ($data[$k] === '' || $data[$k] === null)) $data[$k] = 0;
        }
        if (array_key_exists('type', $data) && blank($data['type'])) $data['type'] = 'retail';
        if (array_key_exists('photo', $data)) {
            $data['photo'] = MediaStorage::replace($customer->photo, $data['photo']);
        }

        return $this->customers->update($customer, $data);
    }
}
