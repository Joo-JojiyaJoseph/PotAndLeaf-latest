<?php

namespace App\Console\Commands;

use App\Services\RentalNotificationService;
use Illuminate\Console\Command;

class AlertOverdueRentals extends Command
{
    protected $signature = 'rentals:alert-overdue {--company=}';

    protected $description = 'Send rental return/payment reminders and overdue alerts via WhatsApp and SMS';

    public function handle(RentalNotificationService $notifications): int
    {
        $companyId = $this->option('company') ? (int) $this->option('company') : null;
        $result = $notifications->sendOverdueAlerts($companyId);

        $this->info("Return overdue: {$result['return_alerts']}, payment overdue: {$result['payment_alerts']}, return reminders: {$result['return_reminders']}, payment reminders: {$result['payment_reminders']}.");

        return self::SUCCESS;
    }
}
