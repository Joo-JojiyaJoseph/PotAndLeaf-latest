<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CommissionEngine;
use Illuminate\Console\Command;

class AccrueManagerCommissionDaily extends Command
{
    protected $signature = 'commission:accrue-manager-daily {--company=} {--date=}';

    protected $description = 'Accrue branch manager commission for one business day';

    public function handle(CommissionEngine $engine): int
    {
        $date = $this->option('date') ?: now()->subDay()->toDateString();
        $companies = $this->option('company')
            ? Company::whereKey($this->option('company'))->get()
            : Company::where('is_active', true)->get();

        $total = 0;
        foreach ($companies as $company) {
            $created = $engine->accrueManagerCommissionForDate($company->id, $date);
            $count = $created->count();
            $total += $count;
            $this->info("{$company->name}: {$count} daily manager row(s) for {$date}");
        }

        $this->info("Total daily manager commission rows: {$total}");

        return self::SUCCESS;
    }
}
