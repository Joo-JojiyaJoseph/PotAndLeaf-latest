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

        // Balances are kept by bills and receipts; the form never sets them directly.
        unset($data['outstanding'], $data['advance_balance']);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($customer, $data) {
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->first() ?? $customer;
            // The form shows the outstanding still unpaid; what was already received stays recorded.
            if (array_key_exists('opening_left', $data)) {
                $receipts = app(\App\Services\ReceiptService::class);
                $paid = round((float) $locked->opening_balance - $receipts->openingLeft($locked), 2);
                $data['opening_balance'] = round((float) ($data['opening_left'] ?: 0) + $paid, 2);
            }
            unset($data['opening_left']);
            // A changed outstanding-from-before changes what the customer owes by the same amount.
            if (array_key_exists('opening_balance', $data)) {
                $delta = round((float) $data['opening_balance'] - (float) $locked->opening_balance, 2);
                if (abs($delta) >= 0.005) {
                    $data['outstanding'] = round((float) $locked->outstanding + $delta, 2);
                }
            }

            return $this->customers->update($locked, $data);
        });
    }
}
