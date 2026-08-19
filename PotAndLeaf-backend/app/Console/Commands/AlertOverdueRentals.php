<?php

namespace App\Console\Commands;

use App\Services\RentalNotificationService;
use Illuminate\Console\Command;

class AlertOverdueRentals extends Command
{
    protected $signature = 'rentals:alert-overdue {--company=}';

    protected $description = 'Send overdue alerts for late returns and unpaid rental invoices';

    public function handle(RentalNotificationService $notifications): int
    {
        $companyId = $this->option('company') ? (int) $this->option('company') : null;
        $result = $notifications->sendOverdueAlerts($companyId);

        $this->info("Return overdue alerts: {$result['return_alerts']}, payment overdue alerts: {$result['payment_alerts']}.");

        return self::SUCCESS;
    }
}
