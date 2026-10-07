<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CommissionNotificationService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendEodCommissionSummaries extends Command
{
    protected $signature = 'commission:send-eod {--company=} {--date=} {--force}';

    protected $description = 'Send end-of-day WhatsApp commission & incentive summaries to staff';

    public function handle(CommissionNotificationService $notifier, SettingsService $settings): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        $force = (bool) $this->option('force');
        $companies = $this->option('company')
            ? Company::whereKey($this->option('company'))->get()
            : Company::where('is_active', true)->get();

        $totalSent = 0;
        foreach ($companies as $company) {
            if (! $force && ! $this->option('date') && ! $this->isAtOrPastSendTime($settings, $company->id)) {
                $sendAt = $settings->get($company->id, 'eod_commission_send_time') ?: '20:00';
                $this->info("{$company->name}: skipped (before {$sendAt})");
                continue;
            }

            $result = $notifier->sendEodSummaries($company->id, $date, $force);
            $totalSent += $result['sent'];
            $this->info("{$company->name}: sent {$result['sent']}, skipped {$result['skipped']}");
            foreach ($result['errors'] as $err) {
                $this->warn($err);
            }
        }

        $this->info("Total EOD summaries sent: {$totalSent}");

        return self::SUCCESS;
    }

    private function isAtOrPastSendTime(SettingsService $settings, int|string $companyId): bool
    {
        $timezone = $settings->get($companyId, 'company_timezone') ?: 'Asia/Kolkata';
        $raw = $settings->get($companyId, 'eod_commission_send_time') ?: '20:00';
        try {
            $now = now()->timezone($timezone)->format('H:i');
            $scheduled = Carbon::createFromFormat('H:i', $raw, $timezone)->format('H:i');
        } catch (\Throwable) {
            return now()->timezone('Asia/Kolkata')->format('H:i') >= '20:00';
        }

        return $now >= $scheduled;
    }
}
