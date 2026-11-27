<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LandscapeDailyStatement;
use App\Models\LandscapeProject;
use App\Models\LandscapeWorkEntry;
use App\Models\Location;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The paper "Daily Work Statement — Landscape": per day and landscape branch, the sites worked
 * (workers, cash paid, materials, bill / cash received) plus other receipts and payments, and the cash balance.
 */
class LandscapeStatementController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        $this->allow($request, 'landscape.view');
        $companyId = $this->companyId($request);
        $data = $request->validate(['date' => ['required', 'date'], 'location_id' => ['required', 'uuid']]);
        $this->division($companyId, $data['location_id']);

        $row = LandscapeDailyStatement::query()->where('company_id', $companyId)->where('location_id', $data['location_id'])->whereDate('statement_date', $data['date'])->first();
        $previous = LandscapeDailyStatement::query()->where('company_id', $companyId)->where('location_id', $data['location_id'])->whereDate('statement_date', '<', $data['date'])->orderByDesc('statement_date')->first();

        return $this->ok([
            'statement' => $row ? $this->row($row) : null,
            'opening_from_previous' => $previous ? (float) $previous->closing_balance : 0.0,
            'previous_date' => $previous?->statement_date?->toDateString(),
            'projects' => LandscapeProject::query()->forCompany($companyId)->where('location_id', $data['location_id'])->whereNotIn('status', ['cancelled'])->orderByDesc('created_at')->limit(300)
                ->get(['id', 'project_no', 'title', 'customer_name', 'service_type', 'site_area_sqft', 'status'])
                ->map(fn ($p) => ['id' => $p->id, 'label' => $p->project_no.' · '.$p->title.($p->customer_name ? ' · '.$p->customer_name : ''), 'party' => $p->customer_name ?: $p->title, 'work_type' => LandscapeProject::SERVICES[$p->service_type] ?? $p->service_type, 'sqft' => $p->site_area_sqft !== null ? (float) $p->site_area_sqft : null, 'status' => $p->status]),
            'can' => ['manage' => $request->user()->hasPermission('landscape.manage', $companyId)],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->allow($request, 'landscape.view');
        $companyId = $this->companyId($request);
        $rows = LandscapeDailyStatement::query()->where('company_id', $companyId)
            ->when(filled($request->query('location_id')), fn ($q) => $q->where('location_id', $request->query('location_id')))
            ->orderByDesc('statement_date')->limit(60)->get()
            ->map(fn ($s) => ['id' => $s->id, 'date' => $s->statement_date->toDateString(), 'location_id' => $s->location_id, 'sites' => count($s->sites ?? []), 'receipts' => (float) $s->total_receipts, 'payments' => (float) $s->total_payments, 'closing' => (float) $s->closing_balance]);

        return $this->ok($rows);
    }

    public function save(Request $request): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        $companyId = $this->companyId($request);
        $data = $request->validate([
            'date'          => ['required', 'date'],
            'location_id'   => ['required', 'uuid'],
            'opening_balance' => ['nullable', 'numeric'],
            'notes'         => ['nullable', 'string', 'max:2000'],
            'sites'         => ['nullable', 'array', 'max:30'],
            'sites.*.project_id' => ['nullable', 'uuid', Rule::exists('landscape_projects', 'id')->where('company_id', $companyId)],
            'sites.*.party_name' => ['nullable', 'string', 'max:160'],
            'sites.*.work_type'  => ['nullable', 'string', 'max:80'],
            'sites.*.sqft'       => ['nullable', 'numeric', 'min:0'],
            'sites.*.workers'    => ['nullable', 'array', 'max:40'],
            'sites.*.workers.*.name' => ['nullable', 'string', 'max:80'],
            'sites.*.workers.*.wage' => ['nullable', 'numeric', 'min:0'],
            'sites.*.payments'   => ['nullable', 'array', 'max:40'],
            'sites.*.payments.*.name'   => ['nullable', 'string', 'max:120'],
            'sites.*.payments.*.amount' => ['nullable', 'numeric', 'min:0'],
            'sites.*.materials'  => ['nullable', 'array', 'max:40'],
            'sites.*.materials.*.name'   => ['nullable', 'string', 'max:120'],
            'sites.*.materials.*.amount' => ['nullable', 'numeric', 'min:0'],
            'sites.*.work_status'   => ['nullable', 'string', 'max:60'],
            'sites.*.bill_amount'   => ['nullable', 'numeric', 'min:0'],
            'sites.*.cash_received' => ['nullable', 'numeric', 'min:0'],
            'other_receipts'  => ['nullable', 'array', 'max:40'],
            'other_receipts.*.name'   => ['nullable', 'string', 'max:120'],
            'other_receipts.*.amount' => ['nullable', 'numeric', 'min:0'],
            'other_payments'  => ['nullable', 'array', 'max:40'],
            'other_payments.*.name'   => ['nullable', 'string', 'max:120'],
            'other_payments.*.amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $this->division($companyId, $data['location_id']);

        $lines = fn ($rows) => collect($rows ?? [])->filter(fn ($r) => filled($r['name'] ?? null) || (float) ($r['amount'] ?? 0) > 0)->map(fn ($r) => ['name' => trim((string) ($r['name'] ?? '')), 'amount' => round((float) ($r['amount'] ?? 0), 2)])->values()->all();
        $sum = fn ($rows) => round(collect($rows)->sum('amount'), 2);

        $sites = [];
        foreach ($data['sites'] ?? [] as $s) {
            $payments = $lines($s['payments'] ?? []);
            $materials = $lines($s['materials'] ?? []);
            $bill = round((float) ($s['bill_amount'] ?? 0), 2);
            $cash = round((float) ($s['cash_received'] ?? 0), 2);
            $site = [
                'project_id' => $s['project_id'] ?? null, 'party_name' => trim((string) ($s['party_name'] ?? '')), 'work_type' => trim((string) ($s['work_type'] ?? '')),
                'sqft' => isset($s['sqft']) && $s['sqft'] !== '' ? (float) $s['sqft'] : null,
                'workers' => collect($s['workers'] ?? [])->map(fn ($w) => ['name' => trim((string) ($w['name'] ?? '')), 'wage' => round((float) ($w['wage'] ?? 0), 2)])->filter(fn ($w) => $w['name'] !== '')->values()->all(),
                'payments' => $payments, 'payments_total' => $sum($payments), 'materials' => $materials, 'materials_total' => $sum($materials),
                'work_status' => trim((string) ($s['work_status'] ?? '')), 'bill_amount' => $bill, 'cash_received' => $cash, 'balance' => round($bill - $cash, 2),
            ];
            if ($site['party_name'] === '' && ! $site['project_id'] && ! $payments && ! $materials && ! $site['workers'] && ! $bill && ! $cash) {
                continue; // an empty column
            }
            $sites[] = $site;
        }
        $otherR = $lines($data['other_receipts'] ?? []);
        $otherP = $lines($data['other_payments'] ?? []);
        $receipts = round(collect($sites)->sum('cash_received') + $sum($otherR), 2);
        $payments = round(collect($sites)->sum('payments_total') + $sum($otherP), 2);
        $opening = round((float) ($data['opening_balance'] ?? 0), 2);

        $st = DB::transaction(function () use ($request, $companyId, $data, $sites, $otherR, $otherP, $receipts, $payments, $opening) {
            $st = LandscapeDailyStatement::query()->where('company_id', $companyId)->where('location_id', $data['location_id'])->whereDate('statement_date', $data['date'])->first()
                ?? new LandscapeDailyStatement(['company_id' => $companyId, 'location_id' => $data['location_id'], 'created_by' => $request->user()->id]);
            $st->fill([
                'statement_date' => $data['date'], 'opening_balance' => $opening, 'sites' => $sites, 'other_receipts' => $otherR, 'other_payments' => $otherP,
                'total_receipts' => $receipts, 'total_payments' => $payments, 'closing_balance' => round($opening + $receipts - $payments, 2), 'notes' => $data['notes'] ?? null,
            ])->save();
            $this->syncProjectCosts($st);

            return $st;
        });

        return $this->ok(['statement' => $this->row($st), 'synced' => $this->syncedCount($st)], 'Daily statement saved.');
    }

    public function destroy(Request $request, LandscapeDailyStatement $landscapeDailyStatement): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        abort_unless((string) $landscapeDailyStatement->company_id === (string) $this->companyId($request), 404);
        DB::transaction(function () use ($landscapeDailyStatement) {
            LandscapeWorkEntry::where('statement_id', $landscapeDailyStatement->id)->delete();
            $landscapeDailyStatement->delete();
        });

        return $this->message('Statement deleted.');
    }

    /** A site linked to a running project passes its cash payments and materials to that project's day-wise cost. */
    private function syncProjectCosts(LandscapeDailyStatement $st): void
    {
        LandscapeWorkEntry::where('statement_id', $st->id)->delete();
        foreach ($st->sites ?? [] as $site) {
            if (! filled($site['project_id'] ?? null)) {
                continue;
            }
            $p = LandscapeProject::query()->forCompany($st->company_id)->find($site['project_id']);
            if (! $p || ! in_array($p->status, ['approved', 'in_progress'], true)) {
                continue;
            }
            // Starting the daily work is simply entering the first day: an approved job becomes "in progress".
            if ($p->status === 'approved') {
                $p->update(['status' => 'in_progress', 'start_date' => $p->start_date ?: $st->statement_date->toDateString()]);
            }
            foreach ($site['workers'] ?? [] as $w) {
                if ((float) ($w['wage'] ?? 0) > 0) {
                    LandscapeWorkEntry::create([
                        'company_id' => $st->company_id, 'project_id' => $p->id, 'work_date' => $st->statement_date, 'type' => 'labour', 'category' => null,
                        'name' => $w['name'], 'qty' => 1, 'rate' => $w['wage'], 'amount' => $w['wage'],
                        'notes' => 'From daily work '.$st->statement_date->format('d M'), 'created_by' => $st->created_by, 'statement_id' => $st->id,
                    ]);
                }
            }
            foreach ([['payments', 'other'], ['materials', 'material']] as [$key, $cat]) {
                foreach ($site[$key] ?? [] as $l) {
                    if ((float) $l['amount'] <= 0) {
                        continue;
                    }
                    LandscapeWorkEntry::create([
                        'company_id' => $st->company_id, 'project_id' => $p->id, 'work_date' => $st->statement_date, 'type' => 'expense', 'category' => $cat,
                        'name' => $l['name'] ?: ($cat === 'material' ? 'Material' : 'Cash payment'), 'qty' => 1, 'rate' => $l['amount'], 'amount' => $l['amount'],
                        'notes' => 'From daily statement '.$st->statement_date->format('d M'), 'created_by' => $st->created_by, 'statement_id' => $st->id,
                    ]);
                }
            }
        }
    }

    private function syncedCount(LandscapeDailyStatement $st): int
    {
        return LandscapeWorkEntry::where('statement_id', $st->id)->count();
    }

    private function division(int|string $companyId, string $locationId): void
    {
        abort_unless(Location::forCompany($companyId)->where('is_landscape', true)->whereKey($locationId)->exists(), 422, 'Choose a branch that is a landscape division.');
    }

    /** @return array<string, mixed> */
    private function row(LandscapeDailyStatement $s): array
    {
        return [
            'id' => $s->id, 'date' => $s->statement_date->toDateString(), 'location_id' => $s->location_id, 'opening_balance' => (float) $s->opening_balance,
            'sites' => $s->sites ?? [], 'other_receipts' => $s->other_receipts ?? [], 'other_payments' => $s->other_payments ?? [],
            'total_receipts' => (float) $s->total_receipts, 'total_payments' => (float) $s->total_payments, 'closing_balance' => (float) $s->closing_balance, 'notes' => $s->notes,
        ];
    }

    private function companyId(Request $request): int|string
    {
        return $request->attributes->get('company')->id;
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->companyId($request)), 403);
    }
}
