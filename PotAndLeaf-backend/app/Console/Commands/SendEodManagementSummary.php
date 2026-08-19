<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\EodManagementSummaryService;
use Illuminate\Console\Command;

class SendEodManagementSummary extends Command
{
    protected $signature = 'eod:send-management-summary {--company=} {--date=} {--force}';

    protected $description = 'Send end-of-day management summary to HO via WhatsApp and email';

    public function handle(EodManagementSummaryService $service): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        $companies = $this->option('company')
            ? Company::whereKey($this->option('company'))->get()
            : Company::where('is_active', true)->get();

        foreach ($companies as $company) {
            $result = $service->send($company->id, $date, (bool) $this->option('force'));
            $this->info("{$company->name}: WhatsApp {$result['whatsapp_sent']}, email {$result['email_sent']}, skipped {$result['skipped']}");
            foreach ($result['errors'] as $err) {
                $this->warn($err);
            }
        }

        return self::SUCCESS;
    }
}
