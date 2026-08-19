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
use App\Models\CommissionPayout;
use App\Models\ManagerCommissionRule;
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

        return $this->ok(['staff' => $staff]);
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
        $this->allow($request, 'commission.view');
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'period'  => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        return $this->ok($this->commission->compute($this->listCompanyId($request), (int) $validated['user_id'], $validated['period']));
    }

    public function supervisorEntries(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.view');

        $entries = $this->commission->supervisorEntries($this->listCompanyId($request), $request->only(['user_id', 'from', 'to', 'per_page']));
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
        $this->allow($request, 'commission.view');
        $companyId = $this->listCompanyId($request);

        $rows = CommissionTransaction::forCompany($companyId)
            ->with('user:id,name')
            ->when($request->query('user_id'), fn ($q, $uid) => $q->where('user_id', $uid))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('transaction_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('transaction_date', '<=', $d))
            ->orderByDesc('transaction_date')
            ->paginate(50);

        return $this->ok($rows);
    }

    public function dailySummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'date'    => ['nullable', 'date'],
        ]);
        $permission = $request->user()->id === (int) $validated['user_id'] ? 'commission.view_own' : 'commission.view';
        abort_unless(
            $request->user()->is_super_admin || $request->user()->hasPermission($permission, $this->company($request)->id)
            || $request->user()->hasPermission('commission.view', $this->company($request)->id),
            403,
        );

        return $this->ok($this->engine->dailySummary(
            $this->listCompanyId($request),
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
            'targets.*.min_amount'   => ['required', 'numeric', 'min:0'],
            'targets.*.bonus_amount' => ['required', 'numeric', 'min:0'],
        ]);

        CommissionDailyTargetTier::where('commission_rule_id', $commissionRule->id)->delete();
        foreach ($data['targets'] as $i => $target) {
            CommissionDailyTargetTier::create([
                'commission_rule_id' => $commissionRule->id,
                'min_amount'         => $target['min_amount'],
                'bonus_amount'       => $target['bonus_amount'],
                'sort_order'         => $i,
            ]);
        }

        return $this->ok(CommissionDailyTargetTier::where('commission_rule_id', $commissionRule->id)->orderBy('sort_order')->get(), 'Daily targets saved.');
    }

    public function promotions(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.view');

        return $this->ok(CommissionPromotion::forCompany($this->listCompanyId($request))->orderByDesc('start_date')->get());
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
            'is_active' => ['boolean'],
        ]);

        $promo = CommissionPromotion::create(array_merge($data, ['company_id' => $company->id]));

        return $this->created($promo, 'Promotion saved.');
    }

    public function seasonalCareRules(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.manage');

        return $this->ok(SeasonalCareRule::forCompany($this->listCompanyId($request))->orderBy('name')->get());
    }

    public function storeSeasonalCareRule(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.manage');
        $company = $this->company($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'product_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
            'days_after_purchase' => ['required', 'integer', 'min:1'],
            'season_months' => ['nullable', 'array'],
            'message_template' => ['required', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        $rule = SeasonalCareRule::create(array_merge($data, ['company_id' => $company->id]));

        return $this->created($rule, 'Seasonal care rule saved.');
    }

    public function whatsappLogs(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.view');

        $rows = WhatsAppMessageLog::forCompany($this->listCompanyId($request))
            ->when($request->query('message_type'), fn ($q, $t) => $q->where('message_type', $t))
            ->orderByDesc('created_at')
            ->paginate(50);

        return $this->ok($rows);
    }

    public function whatsappTemplates(Request $request): JsonResponse
    {
        $this->allowAny($request, ['whatsapp.templates', 'commission.manage']);

        return $this->ok(WhatsAppTemplate::forCompany($this->listCompanyId($request))->orderBy('slug')->get());
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

        return $this->ok(ManagerCommissionRule::forCompany($this->listCompanyId($request))->with('user:id,name', 'location:id,name')->get());
    }

    public function storeManagerRule(Request $request): JsonResponse
    {
        $this->allow($request, 'commission.manage');
        $company = $this->company($request);
        $data = $request->validate([
            'user_id'        => ['required', 'integer'],
            'location_id'    => ['nullable', 'integer'],
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
}
