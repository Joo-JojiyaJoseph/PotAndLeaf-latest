<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LoyaltyLedgerEntry;
use App\Models\LoyaltyRule;
use App\Services\LoyaltyEngine;
use App\Services\LoyaltyService;
use App\Services\SettingsService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoyaltyController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly LoyaltyService $loyalty,
        private readonly LoyaltyEngine $engine,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->listCompanyId($request);
        $this->allowAny($request, ['loyalty.view', 'customers.view']);

        $customers = Customer::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
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

        $recentLedger = LoyaltyLedgerEntry::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
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

        $totals = Customer::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->selectRaw('COUNT(*) as customers, COALESCE(SUM(loyalty_points), 0) as total_points, SUM(CASE WHEN loyalty_points > 0 THEN 1 ELSE 0 END) as with_points')
            ->first();

        $all = $this->settings->all($this->company($request)->id);

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

    public function adjust(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allowAny($request, ['loyalty.adjust', 'customers.update']);

        $data = $request->validate([
            'customer_id' => ['required', 'uuid'],
            'points'      => ['required', 'integer', 'not_in:0'],
            'reason'      => ['required', 'string', 'max:500'],
        ]);

        $customer = Customer::forCompany($company->id)->findOrFail($data['customer_id']);
        $this->loyalty->adjust($customer, (int) $data['points'], $data['reason'], $request->user()->id);

        return $this->ok([
            'customer_id'    => $customer->id,
            'loyalty_points' => (int) $customer->fresh()->loyalty_points,
        ], 'Points adjusted.');
    }

    public function rules(Request $request): JsonResponse
    {
        $this->allowAny($request, ['loyalty.manage', 'loyalty.view', 'customers.view']);

        return $this->ok($this->engine->rulesForCompany($this->listCompanyId($request)));
    }

    public function storeRule(Request $request): JsonResponse
    {
        $this->allow($request, 'loyalty.manage');
        $company = $this->company($request);
        $data = $request->validate([
            'name'                       => ['required', 'string', 'max:120'],
            'rule_type'                  => ['required', Rule::in(['spend', 'product', 'category', 'customer_tier'])],
            'product_id'                 => ['nullable', 'uuid'],
            'category_id'                => ['nullable', 'uuid'],
            'customer_tier'              => ['nullable', 'string', 'max:30'],
            'earn_rupees'                => ['nullable', 'numeric', 'min:1'],
            'earn_points'                => ['nullable', 'integer', 'min:1'],
            'bonus_points_per_unit'      => ['nullable', 'integer', 'min:0'],
            'min_purchase'               => ['nullable', 'numeric', 'min:0'],
            'max_points_per_transaction' => ['nullable', 'integer', 'min:1'],
            'effective_from'             => ['nullable', 'date'],
            'effective_to'               => ['nullable', 'date'],
            'priority'                   => ['nullable', 'integer', 'min:0'],
            'is_active'                  => ['boolean'],
        ]);

        $rule = LoyaltyRule::create(array_merge($data, ['company_id' => $company->id]));

        return $this->created($rule, 'Loyalty rule saved.');
    }

    public function destroyRule(Request $request, LoyaltyRule $loyaltyRule): JsonResponse
    {
        $this->allow($request, 'loyalty.manage');
        abort_unless((string) $loyaltyRule->company_id === (string) $this->company($request)->id, 404);
        $loyaltyRule->delete();

        return $this->message('Loyalty rule removed.');
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    private function allowAny(Request $request, array $permissions): void
    {
        $companyId = $this->company($request)->id;
        foreach ($permissions as $permission) {
            if ($request->user()->hasPermission($permission, $companyId)) {
                return;
            }
        }
        abort(403);
    }
}
