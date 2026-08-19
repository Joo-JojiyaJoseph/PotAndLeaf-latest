<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CommissionEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AccrueManagerCommission extends Command
{
    protected $signature = 'commission:accrue-manager {--company=} {--period=}';

    protected $description = 'Accrue branch manager commission for a calendar month';

    public function handle(CommissionEngine $engine): int
    {
        $period = $this->option('period') ?: Carbon::now()->subMonth()->format('Y-m');
        $companies = $this->option('company')
            ? Company::whereKey($this->option('company'))->get()
            : Company::where('is_active', true)->get();

        $total = 0;
        foreach ($companies as $company) {
            $created = $engine->accrueManagerCommission($company->id, $period);
            $count = $created->count();
            $total += $count;
            $this->info("{$company->name}: {$count} manager commission row(s) for {$period}");
        }

        $this->info("Total manager commission rows: {$total}");

        return self::SUCCESS;
    }
}
