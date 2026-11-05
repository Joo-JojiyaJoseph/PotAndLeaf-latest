<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\ReceiptService;
use Illuminate\Console\Command;

/**
 * List customers whose "Owes you" does not match their bills and payments
 * (outstanding from before + unpaid on bills − received on account).
 * Add --fix to set each one to the worked-out figure.
 */
class CheckCustomerOwes extends Command
{
    protected $signature = 'customers:check-owes {--company= : Only this company id} {--fix : Set "Owes you" to the worked-out figure}';

    protected $description = 'Check (and optionally fix) customers whose "Owes you" does not match their bills';

    public function handle(ReceiptService $receipts): int
    {
        $rows = [];
        Customer::query()
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', $id))
            ->orderBy('company_id')->orderBy('name')
            ->each(function (Customer $c) use ($receipts, &$rows) {
                $check = $receipts->owesFromRecords($c);
                if ($check['matches']) {
                    return;
                }
                $rows[] = [$c->company_id, $c->name, $check['stored'], $check['expected'], $check['unpaid_bills'], $check['on_account']];
                if ($this->option('fix')) {
                    $c->forceFill(['outstanding' => $check['expected']])->save();
                }
            });

        if ($rows === []) {
            $this->info('Every customer\'s "Owes you" matches their bills.');

            return self::SUCCESS;
        }

        $this->table(['Company', 'Customer', 'Owes you now', 'Bills say', 'Unpaid on bills', 'Paid on account'], $rows);
        $this->line($this->option('fix') ? count($rows).' customer(s) fixed.' : 'Run again with --fix to correct them.');

        return self::SUCCESS;
    }
}
