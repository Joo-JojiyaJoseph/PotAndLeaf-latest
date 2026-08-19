<?php

namespace App\Console\Commands;

use App\Services\RentalService;
use Illuminate\Console\Command;

class BillDueRentals extends Command
{
    protected $signature = 'rentals:bill-due {--company=}';

    protected $description = 'Generate rental invoices for active rentals whose billing cycle is due';

    public function handle(RentalService $rentals): int
    {
        $companyId = $this->option('company');
        $result = $rentals->billDueRentals($companyId ? (int) $companyId : null);

        $this->info("Billed {$result['billed']} rental(s), skipped {$result['skipped']}.");
        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
