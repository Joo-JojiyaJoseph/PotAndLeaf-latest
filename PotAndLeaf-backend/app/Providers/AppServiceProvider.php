<?php

namespace App\Providers;

use App\Contracts\WhatsAppSender;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WhatsAppSender::class, WhatsAppService::class);
    }

    public function boot(): void
    {
        RateLimiter::for('company-api', function (Request $request) {
            $key = $request->attributes->get('company_api_key')?->id
                ?: ($request->header('X-Api-Key') ?: $request->ip());

            return Limit::perMinute(60)->by((string) $key);
        });
    }
}
