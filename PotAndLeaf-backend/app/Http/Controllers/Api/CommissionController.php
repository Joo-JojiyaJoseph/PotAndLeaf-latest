<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\StoreCommissionPayoutRequest;
use App\Http\Requests\Commission\UpsertCommissionRuleRequest;
use App\Http\Resources\CommissionPayoutResource;
use App\Http\Resources\CommissionRuleResource;
use App\Models\CommissionDailyTargetTier;
use App\Models\CommissionPromotion;
use App\Models\CommissionRule;
use App\Models\CommissionTier;
use App\Models\CommissionTransaction;
use App\Models\SeasonalCareSend;
use App\Models\SupervisorCommissionRate;
use App\Models\CommissionPayout;
use App\Models\Location;
use App\Models\ManagerCommissionRule;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SeasonalCareRule;
use App\Models\User;
use App\Models\WhatsAppMessageLog;
use App\Models\WhatsAppTemplate;
use App\Support\WhatsApp\TemplateRenderer;
use App\Services\CommissionEngine;
use App\Services\CommissionNotificationService;
use App\Services\CommissionService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(
        private readonly CommissionService $commission,
        private readonly CommissionEngine $engine,
        private readonly CommissionNotificationService $notifier,
    ) {}

    public function formData(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'commission.view');

        $staff = User::query()
            ->activeMembers($company->id)
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        $locations = Location::forCompany($company->id)->orderBy('name')->get(['id', 'name']);
        $products = Product::forCompany($company->id)->where('status', 'active')->orderBy('name')->limit(400)->get(['id', 'name']);
        $categories = ProductCategory::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return $this->ok([
            'staff' => $staff,
            'locations' => $locations,
            'products' => $products,
            'categories' => $categories,
        ]);
    }

    public function rules(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.view');

        return $this->ok(CommissionRuleResource::collection($this->commission->rules($this->listCompanyId($request))));
    }

    public function upsertRule(UpsertCommissionRuleRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $rule = $this->commission->upsertRule($company->id, $request->validated());

        return $this->ok(new CommissionRuleResource($rule), 'Commission rule saved.');
    }

    public function compute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'period'  => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);
        $this->assertCanViewStaff($request, (int) $validated['user_id']);

        return $this->ok($this->commission->compute($this->statementCompanyId($request), (int) $validated['user_id'], $validated['period']));
    }

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'from'    => ['required', 'date'],
            'to'      => ['required', 'date', 'after_or_equal:from'],
        ]);
        $this->assertCanViewStaff($request, (int) $validated['user_id']);

        return $this->ok($this->commission->rangeSummary(
            $this->statementCompanyId($request),
            (int) $validated['user_id'],
            $validated['from'],
            $validated['to'],
        ));
    }

    public function supervisorEntries(Request $request): JsonResponse
    {
        $scope = $this->commissionScope($request);
        $filters = $request->only(['user_id', 'from', 'to', 'per_page']);
        if ($scope['mode'] === 'own') {
            $filters['user_id'] = $scope['user_id'];
        }

        $entries = $this->commission->supervisorEntries($this->listCompanyId($request), $filters);
        $entries->getCollection()->transform(fn ($e) => [
            'id'            => $e->id,
            'user_id'       => $e->user_id,
            'user_name'     => $e->user?->name,
            'product_name'  => $e->product?->name,
            'trigger_event' => $e->trigger_event,
            'qty'           => (float) $e->qty,
            'amount'        => (float) $e->amount,
            'accrued_date'  => optional($e->accrued_date)->toDateString(),
            'reference_type'=> $e->reference_type,
        ]);

        return $this->ok($entries);
    }

    public function payouts(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.view');

        return $this->ok(CommissionPayoutResource::collection($this->commission->payouts($this->listCompanyId($request))));
    }

    public function storePayout(StoreCommissionPayoutRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $payout = $this->commission->recordPayout($company->id, $request->validated());

        return $this->created(new CommissionPayoutResource($payout), 'Commission payout recorded.');
    }

    public function destroyPayout(Request $request, CommissionPayout $commissionPayout): JsonResponse
    {
        $this->allow($request, 'commission.pay');
        abort_unless((string) $commissionPayout->company_id === (string) $this->company($request)->id, 404);
        $this->commission->deletePayout($commissionPayout);

        return $this->message('Payout removed.');
    }

    public function transactions(Request $request): JsonResponse
    {
        $companyId = $this->listCompanyId($request);
        $scope = $this->commissionScope($request);

        // $companyId is null when a super-admin views "All companies".
        $rows = CommissionTransaction::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->with('user:id,name')
            ->when($request->query('user_id'), fn ($q, $uid) => $q->where('user_id', $uid))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('transaction_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('transaction_date', '<=', $d))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('role'), fn ($q, $role) => $q->where('role', $role))
            ->when($request->query('commission_type'), fn ($q, $type) => $q->where('commission_type', $type))
            ->when($request->query('branch_id'), fn ($q, $branch) => $q->where('branch_id', $branch));
        $this->restrictCommissionQuery($rows, $scope);
        $rows = $rows->orderByDesc('transaction_date')->paginate(50);

        return $this->ok($rows);
    }

    public function dailySummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'date'    => ['nullable', 'date'],
        ]);
        $this->assertCanViewStaff($request, (int) $validated['user_id']);

        return $this->ok($this->engine->dailySummary(
            $this->statementCompanyId($request),
            (int) $validated['user_id'],
            $validated['date'] ?? now()->toDateString(),
        ));
    }

    public function sendEod(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.manage');
        $company = $this->company($request);
        $date = $request->input('date') ?: now()->toDateString();

        return $this->ok($this->notifier->sendEodSummaries($company->id, $date, (bool) $request->boolean('force')), 'EOD summaries processed.');
    }

    public function syncTiers(Request $request, CommissionRule $commissionRule): JsonResponse
    {
        $this->allow($request, 'commission.manage');
        abort_unless((string) $commissionRule->company_id === (string) $this->company($request)->id, 404);

        $data = $request->validate([
            'tiers' => ['required', 'array'],
            'tiers.*.min_amount' => ['required', 'numeric', 'min:0'],
            'tiers.*.max_amount' => ['nullable', 'numeric'],
            'tiers.*.percent'    => ['required', 'numeric', 'min:0'],
            'tiers.*.product_id' => ['nullable', 'uuid'],
            'tiers.*.category_id'=> ['nullable', 'uuid'],
        ]);

        foreach ($data['tiers'] as $i => $tier) {
            if (isset($tier['max_amount']) && $tier['max_amount'] !== null && (float) $tier['max_amount'] <= (float) $tier['min_amount']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "tiers.$i.max_amount" => 'Each tier maximum must be greater than its minimum.',
                ]);
            }
        }

        $grouped = collect($data['tiers'])->groupBy(fn ($t) => ($t['product_id'] ?? '').'|'.($t['category_id'] ?? ''));
        foreach ($grouped as $group) {
            $rows = $group->values();
            for ($i = 0; $i < $rows->count(); $i++) {
                for ($j = $i + 1; $j < $rows->count(); $j++) {
                    $aEnd = $rows[$i]['max_amount'] ?? INF;
                    $bEnd = $rows[$j]['max_amount'] ?? INF;
                    if ((float) $rows[$i]['min_amount'] < (float) $bEnd && (float) $rows[$j]['min_amount'] < (float) $aEnd) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'tiers' => 'Commission tiers cannot overlap. Adjacent bands may meet (for example 0–50000 then 50000+).',
                        ]);
                    }
                }
            }
        }

        CommissionTier::where('commission_rule_id', $commissionRule->id)->delete();
        foreach ($data['tiers'] as $i => $tier) {
            CommissionTier::create([
                'commission_rule_id' => $commissionRule->id,
                'product_id'         => $tier['product_id'] ?? null,
                'category_id'        => $tier['category_id'] ?? null,
                'min_amount'         => $tier['min_amount'],
                'max_amount'         => $tier['max_amount'] ?? null,
                'percent'            => $tier['percent'],
                'sort_order'         => $i,
            ]);
        }

        return $this->ok(CommissionTier::where('commission_rule_id', $commissionRule->id)->orderBy('sort_order')->get(), 'Tiers saved.');
    }

    public function syncDailyTargets(Request $request, CommissionRule $commissionRule): JsonResponse
    {
        $this->allow($request, 'commission.manage');
        abort_unless((string) $commissionRule->company_id === (string) $this->company($request)->id, 404);

        $data = $request->validate([
            'targets' => ['required', 'array'],
            'targets.*.min_amount'    => ['required', 'numeric', 'min:0'],
            'targets.*.bonus_amount'  => ['nullable', 'numeric', 'min:0'],
            'targets.*.bonus_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        CommissionDailyTargetTier::where('commission_rule_id', $commissionRule->id)->delete();
        foreach ($data['targets'] as $i => $target) {
            CommissionDailyTargetTier::create([
                'commission_rule_id' => $commissionRule->id,
                'min_amount'         => $target['min_amount'],
                'bonus_amount'       => $target['bonus_amount'] ?? 0,
                'bonus_percent'      => $target['bonus_percent'] ?? 0,
                'sort_order'         => $i,
            ]);
        }

        return $this->ok(CommissionDailyTargetTier::where('commission_rule_id', $commissionRule->id)->orderBy('sort_order')->get(), 'Daily targets saved.');
    }

    public function promotions(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.view');

        return $this->ok(
            CommissionPromotion::query()->when(($cid = $this->listCompanyId($request)) !== null, fn ($q) => $q->forCompany($cid))
                ->with(['product:id,name', 'category:id,name'])
                ->orderByDesc('start_date')
                ->get()
        );
    }

    public function storePromotion(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.manage');
        $company = $this->company($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'product_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'min_qty' => ['nullable', 'numeric', 'min:0'],
            'bonus_per_unit' => ['nullable', 'numeric', 'min:0'],
            'bonus_fixed' => ['nullable', 'numeric', 'min:0'],
            'bonus_percent' => ['nullable', 'numeric', 'min:0'],
            'eligible_user_ids' => ['nullable', 'array'],
            'eligible_user_ids.*' => ['integer'],
            'is_active' => ['boolean'],
        ]);

        $promo = CommissionPromotion::create(array_merge($data, ['company_id' => $company->id]));

        return $this->created($promo, 'Promotion saved.');
    }

    public function seasonalCareRules(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.manage');

        return $this->ok(SeasonalCareRule::query()->when(($cid = $this->listCompanyId($request)) !== null, fn ($q) => $q->forCompany($cid))->orderBy('name')->get());
    }

    public function storeSeasonalCareRule(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.manage');
        $company = $this->company($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'product_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
            'days_after_purchase' => ['nullable', 'integer', 'min:0'],
            'season' => ['nullable', 'in:summer,monsoon,winter'],
            'timing' => ['nullable', 'in:days_after_purchase,season_start'],
            'season_months' => ['nullable', 'array'],
            'message_template' => ['required', 'string', 'max:2000'],
            'max_sends_per_customer' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $rule = SeasonalCareRule::create(array_merge($data, [
            'company_id' => $company->id,
            'timing' => $data['timing'] ?? 'days_after_purchase',
            'days_after_purchase' => $data['days_after_purchase'] ?? 0,
        ]));

        return $this->created($rule, 'Seasonal care rule saved.');
    }

    public function seasonalCareSends(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.manage');
        $companyId = $this->listCompanyId($request);

        $rows = SeasonalCareSend::query()
            ->when($companyId !== null, fn ($q) => $q->whereHas('rule', fn ($r) => $r->where('company_id', $companyId)))
            ->with('rule:id,name,season')
            ->orderByDesc('sent_at')
            ->paginate(50);

        return $this->ok($rows);
    }

    public function supervisorRates(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.view');

        return $this->ok(
            SupervisorCommissionRate::query()->when(($cid = $this->listCompanyId($request)) !== null, fn ($q) => $q->forCompany($cid))
                ->with(['user:id,name', 'product:id,name', 'category:id,name'])
                ->orderByDesc('created_at')
                ->get()
        );
    }

    public function storeSupervisorRate(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.manage');
        $company = $this->company($request);
        $data = $request->validate([
            'user_id'        => ['required', 'integer'],
            'product_id'     => ['nullable', 'uuid'],
            'category_id'    => ['nullable', 'uuid'],
            'rate_type'      => ['required', 'in:percent,per_unit'],
            'rate'           => ['required', 'numeric', 'min:0'],
            'effective_from' => ['nullable', 'date'],
            'effective_to'   => ['nullable', 'date'],
            'is_active'      => ['boolean'],
        ]);
        if (empty($data['product_id']) && empty($data['category_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'product_id' => 'Choose a product or a category for the supervisor rate.',
            ]);
        }

        $rate = SupervisorCommissionRate::create(array_merge($data, ['company_id' => $company->id]));

        return $this->created($rate->load('user:id,name', 'product:id,name', 'category:id,name'), 'Supervisor rate saved.');
    }

    public function whatsappLogs(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.view');

        $rows = WhatsAppMessageLog::query()->when(($cid = $this->listCompanyId($request)) !== null, fn ($q) => $q->forCompany($cid))
            ->when($request->query('message_type'), fn ($q, $t) => $q->where('message_type', $t))
            ->orderByDesc('created_at')
            ->paginate(50);

        return $this->ok($rows);
    }

    public function whatsappTemplates(Request $request): JsonResponse
    {
        $this->allowAny($request, ['whatsapp.templates', 'commission.manage']);

        return $this->ok(WhatsAppTemplate::query()->when(($cid = $this->listCompanyId($request)) !== null, fn ($q) => $q->forCompany($cid))->orderBy('slug')->get());
    }

    public function storeWhatsAppTemplate(Request $request): JsonResponse
    {
        $this->allow($request, 'whatsapp.templates');
        $company = $this->company($request);
        $data = $request->validate([
            'slug'      => ['required', 'string', 'max:40'],
            'name'      => ['required', 'string', 'max:120'],
            'body'      => ['required', 'string', 'max:4000'],
            'is_active' => ['boolean'],
        ]);

        $tpl = WhatsAppTemplate::updateOrCreate(
            ['company_id' => $company->id, 'slug' => $data['slug']],
            ['name' => $data['name'], 'body' => $data['body'], 'is_active' => $data['is_active'] ?? true],
        );

        return $this->created($tpl, 'Template saved.');
    }

    public function managerRules(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.view');

        return $this->ok(ManagerCommissionRule::query()->when(($cid = $this->listCompanyId($request)) !== null, fn ($q) => $q->forCompany($cid))->with('user:id,name', 'location:id,name')->get());
    }

    public function storeManagerRule(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.manage');
        $company = $this->company($request);
        $data = $request->validate([
            'user_id'        => ['required', 'integer'],
            'location_id'    => ['nullable', 'uuid'],
            'percent'        => ['required', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['nullable', 'date'],
            'effective_to'   => ['nullable', 'date'],
            'is_active'      => ['boolean'],
        ]);

        $rule = ManagerCommissionRule::updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $data['user_id'], 'location_id' => $data['location_id'] ?? null],
            [
                'percent'        => $data['percent'],
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to'   => $data['effective_to'] ?? null,
                'is_active'      => $data['is_active'] ?? true,
            ],
        );

        return $this->created($rule->load('user:id,name', 'location:id,name'), 'Manager rule saved.');
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

    /**
     * A staff member's commission statement belongs to one company. When a
     * super-admin is viewing "All companies" (listCompanyId = null), use the
     * company that is open instead of failing.
     */
    private function statementCompanyId(Request $request): int|string
    {
        return $this->listCompanyId($request) ?? $this->company($request)->id;
    }

    /** @return array{mode: string, user_id?: int, locations?: array<int, string>} */
    private function commissionScope(Request $request): array
    {
        $user = $request->user();
        $companyId = $this->company($request)->id;
        if ($user->is_super_admin || $user->hasPermission('*', $companyId)) {
            return ['mode' => 'all'];
        }
        if ($user->hasRole('manager', $companyId) && $user->hasPermission('commission.view', $companyId)) {
            $rules = ManagerCommissionRule::forCompany($companyId)->where('user_id', $user->id)->get();
            if ($rules->isEmpty() || $rules->contains(fn ($rule) => $rule->location_id === null)) {
                return ['mode' => 'all'];
            }

            return [
                'mode'      => 'branch',
                'locations' => $rules->pluck('location_id')->filter()->values()->all(),
                'user_id'   => $user->id,
            ];
        }
        if ($user->hasPermission('commission.view', $companyId)) {
            return ['mode' => 'all'];
        }
        if ($user->hasPermission('commission.view_own', $companyId)) {
            return ['mode' => 'own', 'user_id' => $user->id];
        }
        abort(403);
    }

    private function assertCanViewStaff(Request $request, int $userId): void
    {
        $scope = $this->commissionScope($request);
        if ($scope['mode'] === 'own' && (int) $scope['user_id'] !== $userId) {
            abort(403);
        }
    }

    private function restrictCommissionQuery($query, array $scope, string $userColumn = 'user_id', ?string $branchColumn = 'branch_id')
    {
        if ($scope['mode'] === 'own') {
            $query->where($userColumn, $scope['user_id']);
        } elseif ($scope['mode'] === 'branch') {
            $query->where(function ($inner) use ($scope, $userColumn, $branchColumn) {
                $inner->where($userColumn, $scope['user_id']);
                if ($branchColumn && ! empty($scope['locations'])) {
                    $inner->orWhereIn($branchColumn, $scope['locations']);
                }
            });
        }

        return $query;
    }
}
