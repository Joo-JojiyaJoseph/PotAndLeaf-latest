<?php

namespace App\Services;

use App\Models\LedgerAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ChartOfAccountsService
{
    /** @var array<string, array{code:string,name:string,account_type:string}> */
    public const DEFAULTS = [
        'cash'              => ['code' => 'CASH', 'name' => 'Cash in Hand',        'account_type' => 'asset'],
        'bank'              => ['code' => 'BANK', 'name' => 'Bank Accounts',       'account_type' => 'asset'],
        'ar'                => ['code' => 'AR',   'name' => 'Accounts Receivable', 'account_type' => 'asset'],
        'ap'                => ['code' => 'AP',   'name' => 'Accounts Payable',    'account_type' => 'liability'],
        'sales'             => ['code' => 'SALE', 'name' => 'Sales',               'account_type' => 'income'],
        'purchases'         => ['code' => 'PURC', 'name' => 'Purchases',           'account_type' => 'expense'],
        'other_income'      => ['code' => 'OINC', 'name' => 'Other Income',        'account_type' => 'income'],
        'other_expense'     => ['code' => 'OEXP', 'name' => 'Other Expenses',      'account_type' => 'expense'],
        'customer_advance'  => ['code' => 'CADV', 'name' => 'Customer Advances',   'account_type' => 'liability'],
        'supplier_advance'  => ['code' => 'SADV', 'name' => 'Supplier Advances',   'account_type' => 'asset'],
        'capital'           => ['code' => 'CAP',  'name' => 'Capital',             'account_type' => 'equity'],
    ];

    public function __construct(private readonly SettingsService $settings) {}

    public function ensureForCompany(int|string $companyId): Collection
    {
        if (! Schema::hasTable('ledger_accounts')) {
            return collect();
        }

        foreach (self::DEFAULTS as $key => $row) {
            $opening = 0.0;
            if ($key === 'cash') {
                $opening = (float) $this->settings->get($companyId, 'cash_opening_balance', '0');
            } elseif ($key === 'bank') {
                $opening = (float) $this->settings->get($companyId, 'bank_opening_balance', '0');
            }

            LedgerAccount::firstOrCreate(
                ['company_id' => $companyId, 'system_key' => $key],
                [
                    'code'            => $row['code'],
                    'name'            => $row['name'],
                    'account_type'    => $row['account_type'],
                    'opening_balance' => $opening,
                    'status'          => 'active',
                ],
            );
        }

        return LedgerAccount::forCompany($companyId)->active()->orderBy('code')->get();
    }

    public function accounts(int|string $companyId): Collection
    {
        return $this->ensureForCompany($companyId);
    }

    public function systemAccount(int|string $companyId, string $key): LedgerAccount
    {
        $this->ensureForCompany($companyId);

        $account = LedgerAccount::forCompany($companyId)->where('system_key', $key)->first();
        if (! $account) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'account' => "Ledger account '{$key}' is not configured for this company.",
            ]);
        }

        return $account;
    }
}
