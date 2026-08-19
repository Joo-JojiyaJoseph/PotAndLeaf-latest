<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\SeasonalCareService;
use Illuminate\Console\Command;

class SendSeasonalCareMessages extends Command
{
    protected $signature = 'care:send-seasonal {--company=} {--date=}';

    protected $description = 'Send purchase-history-based seasonal plant care WhatsApp messages';

    public function handle(SeasonalCareService $care): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        $companies = $this->option('company')
            ? Company::whereKey($this->option('company'))->get()
            : Company::where('is_active', true)->get();

        $total = 0;
        foreach ($companies as $company) {
            $result = $care->sendDueMessages($company->id, $date);
            $total += $result['sent'];
            $this->info("{$company->name}: sent {$result['sent']}, skipped {$result['skipped']}");
        }

        $this->info("Total seasonal care messages sent: {$total}");

        return self::SUCCESS;
    }
}
