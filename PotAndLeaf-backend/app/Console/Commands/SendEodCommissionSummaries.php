<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CommissionNotificationService;
use Illuminate\Console\Command;

class SendEodCommissionSummaries extends Command
{
    protected $signature = 'commission:send-eod {--company=} {--date=} {--force}';

    protected $description = 'Send end-of-day WhatsApp commission & incentive summaries to staff';

    public function handle(CommissionNotificationService $notifier): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        $companies = $this->option('company')
            ? Company::whereKey($this->option('company'))->get()
            : Company::where('is_active', true)->get();

        $totalSent = 0;
        foreach ($companies as $company) {
            $result = $notifier->sendEodSummaries($company->id, $date, (bool) $this->option('force'));
            $totalSent += $result['sent'];
            $this->info("{$company->name}: sent {$result['sent']}, skipped {$result['skipped']}");
            foreach ($result['errors'] as $err) {
                $this->warn($err);
            }
        }

        $this->info("Total EOD summaries sent: {$totalSent}");

        return self::SUCCESS;
    }
}
