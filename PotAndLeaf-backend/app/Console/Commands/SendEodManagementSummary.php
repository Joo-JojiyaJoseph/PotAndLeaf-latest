<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\EodManagementSummaryService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendEodManagementSummary extends Command
{
    protected $signature = 'eod:send-management-summary {--company=} {--date=} {--force}';

    protected $description = 'Send end-of-day management summary to HO via WhatsApp and email';

    public function handle(EodManagementSummaryService $service, SettingsService $settings): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        $force = (bool) $this->option('force');
        $companies = $this->option('company')
            ? Company::whereKey($this->option('company'))->get()
            : Company::where('is_active', true)->get();

        foreach ($companies as $company) {
            if (! $force && ! $this->option('date') && ! $this->isAtOrPastSendTime($settings, $company->id)) {
                $sendAt = $settings->get($company->id, 'eod_management_send_time') ?: '20:30';
                $this->info("{$company->name}: skipped (before {$sendAt})");
                continue;
            }

            $result = $service->send($company->id, $date, $force);
            $this->info("{$company->name}: WhatsApp {$result['whatsapp_sent']}, email {$result['email_sent']}, skipped {$result['skipped']}");
            foreach ($result['errors'] as $err) {
                $this->warn($err);
            }
        }

        return self::SUCCESS;
    }

    private function isAtOrPastSendTime(SettingsService $settings, int|string $companyId): bool
    {
        $raw = $settings->get($companyId, 'eod_management_send_time') ?: '20:30';
        try {
            $scheduled = Carbon::createFromFormat('H:i', $raw);
        } catch (\Throwable) {
            $scheduled = Carbon::createFromFormat('H:i', '20:30');
        }

        return now()->format('H:i') >= $scheduled->format('H:i');
    }
}
