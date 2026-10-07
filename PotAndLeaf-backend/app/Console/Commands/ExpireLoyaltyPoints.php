<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\LoyaltyService;
use Illuminate\Console\Command;

class ExpireLoyaltyPoints extends Command
{
    protected $signature = 'loyalty:expire-points {--company=} {--date=}';

    protected $description = 'Expire loyalty points whose earn entries have passed expires_at';

    public function handle(LoyaltyService $loyalty): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        $companies = $this->option('company')
            ? Company::whereKey($this->option('company'))->get()
            : Company::where('is_active', true)->get();

        $total = 0;
        foreach ($companies as $company) {
            $expired = $loyalty->expireDuePoints($company->id, $date);
            $total += $expired;
            $this->info("{$company->name}: expired {$expired} point row(s)");
        }

        $this->info("Total loyalty expiry rows: {$total}");

        return self::SUCCESS;
    }
}
