<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LoyaltyLedgerEntry;
use App\Services\SettingsService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(private readonly SettingsService $settings) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'customers.view');

        $customers = Customer::forCompany($company->id)
            ->orderByDesc('loyalty_points')
            ->orderBy('name')
            ->paginate(min(50, max(1, (int) $request->input('per_page', 25))));

        $customersPayload = $customers->through(fn ($c) => [
            'id'             => $c->id,
            'customer_code'  => $c->customer_code,
            'name'           => $c->name,
            'phone'          => $c->phone,
            'loyalty_points' => (int) $c->loyalty_points,
        ]);

        $recentLedger = LoyaltyLedgerEntry::forCompany($company->id)
            ->with('customer:id,name,customer_code')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(fn ($e) => [
                'id'             => $e->id,
                'customer_id'    => $e->customer_id,
                'customer_name'  => $e->customer?->name,
                'customer_code'  => $e->customer?->customer_code,
                'type'           => $e->type,
                'points'         => (int) $e->points,
                'balance_after'  => (int) $e->balance_after,
                'note'           => $e->note,
                'created_at'     => $e->created_at?->toIso8601String(),
            ]);

        $totals = Customer::forCompany($company->id)
            ->selectRaw('COUNT(*) as customers, COALESCE(SUM(loyalty_points), 0) as total_points, SUM(CASE WHEN loyalty_points > 0 THEN 1 ELSE 0 END) as with_points')
            ->first();

        $all = $this->settings->all($company->id);

        return $this->ok([
            'customers'     => $customersPayload,
            'recent_ledger' => $recentLedger,
            'settings'      => [
                'loyalty_earn_rupees'        => (float) ($all['loyalty_earn_rupees'] ?? 100),
                'loyalty_earn_points'        => (int) ($all['loyalty_earn_points'] ?? 1),
                'loyalty_redeem_rupees'      => (float) ($all['loyalty_redeem_rupees'] ?? 1),
                'loyalty_redeem_cap_percent' => (float) ($all['loyalty_redeem_cap_percent'] ?? 50),
            ],
            'totals' => [
                'customers'    => (int) ($totals->customers ?? 0),
                'with_points'  => (int) ($totals->with_points ?? 0),
                'total_points' => (int) ($totals->total_points ?? 0),
            ],
        ]);
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }
}
