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
        /*
         * Shared list filters: ?from=YYYY-MM-DD&to=YYYY-MM-DD on a date column,
         * and ?search= across number/name columns (and related names, e.g.
         * ['customer' => 'name']).
         */
        \Illuminate\Database\Eloquent\Builder::macro('listFilters', function (array $filters, string $dateColumn, array $searchColumns = [], array $relations = []) {
            /** @var \Illuminate\Database\Eloquent\Builder $this */
            if (filled($filters['from'] ?? null)) {
                $this->whereDate($dateColumn, '>=', $filters['from']);
            }
            if (filled($filters['to'] ?? null)) {
                $this->whereDate($dateColumn, '<=', $filters['to']);
            }
            $term = trim((string) ($filters['search'] ?? ''));
            if ($term !== '' && ($searchColumns || $relations)) {
                $like = '%'.$term.'%';
                $this->where(function ($q) use ($searchColumns, $relations, $like) {
                    foreach ($searchColumns as $col) {
                        $q->orWhere($col, 'like', $like);
                    }
                    foreach ($relations as $relation => $col) {
                        $q->orWhereHas($relation, fn ($r) => $r->where($col, 'like', $like));
                    }
                });
            }

            return $this;
        });

        RateLimiter::for('company-api', function (Request $request) {
            $key = $request->attributes->get('company_api_key')?->id
                ?: ($request->header('X-Api-Key') ?: $request->ip());

            return Limit::perMinute(60)->by((string) $key);
        });
    }
}
