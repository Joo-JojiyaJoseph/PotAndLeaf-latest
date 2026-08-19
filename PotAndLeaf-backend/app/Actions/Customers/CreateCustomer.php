<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;

class CreateCustomer
{
    public function __construct(private readonly CustomerRepositoryInterface $customers) {}

    /** @param array<string,mixed> $data */
    public function handle(int|string $companyId, array $data): Customer
    {
        if (empty($data['customer_code'])) {
            $data['customer_code'] = $this->customers->nextCustomerCode($companyId);
        }
        if (blank($data['type'] ?? null)) $data['type'] = 'retail';
        if (blank($data['status'] ?? null)) $data['status'] = 'active';

        $opening = (float) ($data['opening_balance'] ?? 0);
        foreach (['credit_days', 'credit_limit', 'opening_balance', 'advance_balance', 'loyalty_points'] as $k) {
            if (! isset($data[$k]) || $data[$k] === '' || $data[$k] === null) {
                $data[$k] = 0;
            }
        }
        if (! isset($data['outstanding']) || $data['outstanding'] === '' || $data['outstanding'] === null) {
            $data['outstanding'] = $opening;
        }

        $data['company_id'] = $companyId;

        return $this->customers->create($data);
    }
}
