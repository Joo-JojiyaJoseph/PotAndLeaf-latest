<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\ProductionOrder;
use App\Models\Sale;
use App\Models\StockTransfer;
use App\Models\StockVerification;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class ActivityMonitoringService
{
    public function snapshot(int|string $companyId): array
    {
        $today = now()->toDateString();
        $company = Company::find($companyId);

        $pendingApprovals = [
            'stock_verifications' => StockVerification::forCompany($companyId)->where('status', 'submitted')->count(),
        ];

        $recentLogins = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(function ($t) use ($companyId) {
                $user = User::find($t->tokenable_id);
                if (! $user || ! $user->companies()->where('companies.id', $companyId)->exists()) {
                    return null;
                }

                return [
                    'user_id'   => $user->id,
                    'user_name' => $user->name,
                    'logged_at' => optional($t->created_at)->toDateTimeString(),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $recentLogs = ActivityLog::forCompany($companyId)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($e) => [
                'id'          => $e->id,
                'action'      => $e->action,
                'module'      => $e->module,
                'description' => $e->description,
                'user_name'   => $e->user?->name,
                'created_at'  => $e->created_at?->toIso8601String(),
            ]);

        return [
            'as_of'             => now()->toDateTimeString(),
            'company'           => $company ? ['id' => $company->id, 'name' => $company->name, 'code' => $company->code] : null,
            'pending_approvals' => $pendingApprovals,
            'recent_logins'     => $recentLogins,
            'recent_logs'       => $recentLogs,
            'company_totals'    => [
                'today_sales'          => round((float) Sale::forCompany($companyId)
                    ->where('status', 'confirmed')->whereDate('sale_date', $today)->sum('grand_total'), 2),
                'today_production'     => ProductionOrder::forCompany($companyId)
                    ->where('status', 'completed')->whereDate('completed_at', $today)->count(),
                'in_transit_transfers' => StockTransfer::forCompany($companyId)->where('status', 'in_transit')->count(),
            ],
        ];
    }
}
