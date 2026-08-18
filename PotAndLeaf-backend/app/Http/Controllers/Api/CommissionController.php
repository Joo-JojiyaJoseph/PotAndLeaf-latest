<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\StoreCommissionPayoutRequest;
use App\Http\Requests\Commission\UpsertCommissionRuleRequest;
use App\Http\Resources\CommissionPayoutResource;
use App\Http\Resources\CommissionRuleResource;
use App\Models\CommissionPayout;
use App\Models\User;
use App\Services\CommissionService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(private readonly CommissionService $commission) {}

    public function formData(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'commission.view');

        $staff = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey($company->id))
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        return $this->ok(['staff' => $staff]);
    }

    public function rules(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'commission.view');

        return $this->ok(CommissionRuleResource::collection($this->commission->rules($company->id)));
    }

    public function upsertRule(UpsertCommissionRuleRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $rule = $this->commission->upsertRule($company->id, $request->validated());

        return $this->ok(new CommissionRuleResource($rule), 'Commission rule saved.');
    }

    public function compute(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'commission.view');
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'period'  => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        return $this->ok($this->commission->compute($company->id, (int) $validated['user_id'], $validated['period']));
    }

    public function supervisorEntries(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'commission.view');

        $entries = $this->commission->supervisorEntries($company->id, $request->only(['user_id', 'from', 'to', 'per_page']));
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
        $company = $this->listCompany($request);
        $this->allow($request, 'commission.view');

        return $this->ok(CommissionPayoutResource::collection($this->commission->payouts($company->id)));
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

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }
}
