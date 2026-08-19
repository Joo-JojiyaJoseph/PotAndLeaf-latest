<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('backup:run --type=automatic')->dailyAt('02:00');
Schedule::command('rentals:bill-due')->dailyAt('06:00');
Schedule::command('rentals:alert-overdue')->dailyAt('09:00');
Schedule::command('care:send-seasonal')->dailyAt('10:00');
Schedule::command('commission:send-eod')->dailyAt('20:00');
Schedule::command('eod:send-management-summary')->dailyAt('20:30');
Schedule::command('commission:accrue-manager')->monthlyOn(1, '07:00');
Schedule::command('whatsapp:retry-failed')->hourly();
