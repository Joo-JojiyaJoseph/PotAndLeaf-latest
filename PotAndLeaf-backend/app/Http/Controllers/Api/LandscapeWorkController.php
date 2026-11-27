<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LandscapeCommission;
use App\Models\LandscapeEstimate;
use App\Models\LandscapeProject;
use App\Models\LandscapeWorkEntry;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The life of a landscaping job: estimate (with revisions) → send to client → approve →
 * work order → daily labour & expenses → complete → consolidated report and staff commission.
 */
class LandscapeWorkController extends Controller
{
    use ApiResponse;

    private const EXPENSE_CATEGORIES = ['plants' => 'Plants', 'material' => 'Material', 'transport' => 'Transport', 'other' => 'Other'];

    public function show(Request $request, LandscapeProject $landscapeProject): JsonResponse
    {
        $this->allow($request, 'landscape.view');
        $this->own($request, $landscapeProject);

        return $this->ok($this->payload($request, $landscapeProject));
    }

    // ───────── Estimates ─────────

    public function storeEstimate(Request $request, LandscapeProject $landscapeProject): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        $this->own($request, $landscapeProject);
        $this->assertOpen($landscapeProject);
        $data = $this->validatedEstimate($request);

        $est = DB::transaction(function () use ($request, $landscapeProject, $data) {
            $revision = (int) LandscapeEstimate::where('project_id', $landscapeProject->id)->max('revision') + 1;
            $est = LandscapeEstimate::create($this->figures($data) + [
                'company_id' => $landscapeProject->company_id,
                'project_id' => $landscapeProject->id,
                'revision'   => $revision,
                'estimate_no' => $landscapeProject->project_no.'/R'.$revision,
                'status'     => 'draft',
                'created_by' => $request->user()->id,
            ]);
            $this->saveItems($est, $data['items'] ?? []);
            if (filled($request->input('revise_of'))) {
                LandscapeEstimate::where('project_id', $landscapeProject->id)->whereKey($request->input('revise_of'))
                    ->whereIn('status', ['draft', 'sent'])->update(['status' => 'superseded']);
            }
            $this->syncQuote($landscapeProject);

            return $est;
        });

        return $this->created($this->payload($request, $landscapeProject->fresh()), "Estimate {$est->estimate_no} saved.");
    }

    public function updateEstimate(Request $request, LandscapeEstimate $landscapeEstimate): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        $project = $this->projectOf($request, $landscapeEstimate);
        if ($landscapeEstimate->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft can be edited. Use “New revision” to change an estimate that was sent.']);
        }
        $data = $this->validatedEstimate($request);
        DB::transaction(function () use ($landscapeEstimate, $project, $data) {
            $landscapeEstimate->update($this->figures($data));
            $this->saveItems($landscapeEstimate, $data['items'] ?? []);
            $this->syncQuote($project);
        });

        return $this->ok($this->payload($request, $project->fresh()), 'Estimate updated.');
    }

    public function deleteEstimate(Request $request, LandscapeEstimate $landscapeEstimate): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        $project = $this->projectOf($request, $landscapeEstimate);
        if ($landscapeEstimate->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft can be deleted.']);
        }
        $landscapeEstimate->delete();
        $this->syncQuote($project);

        return $this->ok($this->payload($request, $project->fresh()), 'Draft deleted.');
    }

    public function sendEstimate(Request $request, LandscapeEstimate $landscapeEstimate, WhatsAppService $whatsapp): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        $project = $this->projectOf($request, $landscapeEstimate);
        if (in_array($landscapeEstimate->status, ['superseded', 'approved'], true)) {
            throw ValidationException::withMessages(['status' => 'This estimate cannot be sent any more.']);
        }
        $to = $project->customer_phone;
        if (! filled($to) && filled($project->customer_id)) {
            $to = \App\Models\Customer::query()->whereKey($project->customer_id)->value('whatsapp') ?: \App\Models\Customer::query()->whereKey($project->customer_id)->value('phone');
        }
        $result = $whatsapp->sendMessage($to, $this->estimateMessage($project, $landscapeEstimate));
        if (! $result['success']) {
            return $this->message($result['message'], 422);
        }
        $landscapeEstimate->update(['status' => 'sent', 'sent_at' => now()]);
        $this->syncQuote($project);

        return $this->ok($this->payload($request, $project->fresh()), 'Estimate sent on WhatsApp.');
    }

    public function approveEstimate(Request $request, LandscapeEstimate $landscapeEstimate): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        $project = $this->projectOf($request, $landscapeEstimate);
        $this->assertOpen($project);
        if ($landscapeEstimate->status === 'superseded') {
            throw ValidationException::withMessages(['status' => 'An older revision cannot be approved.']);
        }
        if ($project->approved_estimate_id) {
            throw ValidationException::withMessages(['status' => 'An estimate is already approved. The work order has been made.']);
        }
        DB::transaction(function () use ($landscapeEstimate, $project) {
            $landscapeEstimate->update(['status' => 'approved', 'approved_at' => now()]);
            LandscapeEstimate::where('project_id', $project->id)->whereKeyNot($landscapeEstimate->id)->whereIn('status', ['draft', 'sent'])->update(['status' => 'superseded']);
            $project->update([
                'status'               => 'approved',
                'approved_estimate_id' => $landscapeEstimate->id,
                'approved_at'          => now(),
                'quoted_amount'        => $landscapeEstimate->total,
                'work_order_no'        => 'WO-'.preg_replace('/\D+/', '', $project->project_no),
                'work_order_date'      => now()->toDateString(),
                'work_order_terms'     => $project->work_order_terms ?: self::DEFAULT_TERMS,
            ]);
        });

        return $this->ok($this->payload($request, $project->fresh()), 'Estimate approved — work order made.');
    }

    // ───────── Work order, start, complete ─────────

    public function updateWorkOrder(Request $request, LandscapeProject $landscapeProject): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        $this->own($request, $landscapeProject);
        abort_unless($landscapeProject->approved_estimate_id, 422, 'Approve an estimate first.');
        $data = $request->validate([
            'work_order_terms' => ['nullable', 'string', 'max:5000'],
            'advance_received' => ['nullable', 'numeric', 'min:0'],
            'start_date'       => ['nullable', 'date'],
            'end_date'         => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
        $landscapeProject->update(array_filter([
            'work_order_terms' => $data['work_order_terms'] ?? null,
            'advance_received' => isset($data['advance_received']) ? (float) $data['advance_received'] : null,
            'start_date'       => $data['start_date'] ?? null,
            'end_date'         => $data['end_date'] ?? null,
        ], fn ($v) => $v !== null));

        return $this->ok($this->payload($request, $landscapeProject->fresh()), 'Work order saved.');
    }

    public function start(Request $request, LandscapeProject $landscapeProject): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        $this->own($request, $landscapeProject);
        if ($landscapeProject->status !== 'approved') {
            throw ValidationException::withMessages(['status' => 'Only an approved project can be started.']);
        }
        $landscapeProject->update(['status' => 'in_progress', 'start_date' => $landscapeProject->start_date ?: now()->toDateString()]);

        return $this->ok($this->payload($request, $landscapeProject->fresh()), 'Work started. Add each day’s labour and expenses.');
    }

    public function complete(Request $request, LandscapeProject $landscapeProject): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        $this->own($request, $landscapeProject);
        if ($landscapeProject->status !== 'in_progress') {
            throw ValidationException::withMessages(['status' => 'Only a project in progress can be completed.']);
        }
        $data = $request->validate(['completion_note' => ['nullable', 'string', 'max:2000']]);
        $landscapeProject->update(['status' => 'completed', 'completed_at' => now(), 'end_date' => now()->toDateString(), 'completion_note' => $data['completion_note'] ?? null]);

        return $this->ok($this->payload($request, $landscapeProject->fresh()), 'Work completed. The report is ready.');
    }

    // ───────── Daily labour and expenses ─────────

    public function storeEntry(Request $request, LandscapeProject $landscapeProject): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        $this->own($request, $landscapeProject);
        $this->assertWorking($landscapeProject);
        $data = $this->validatedEntry($request);
        LandscapeWorkEntry::create($data + ['company_id' => $landscapeProject->company_id, 'project_id' => $landscapeProject->id, 'created_by' => $request->user()->id]);

        return $this->created($this->payload($request, $landscapeProject->fresh()), 'Entry added.');
    }

    public function updateEntry(Request $request, LandscapeWorkEntry $landscapeWorkEntry): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        $project = LandscapeProject::findOrFail($landscapeWorkEntry->project_id);
        $this->own($request, $project);
        $this->assertWorking($project);
        $landscapeWorkEntry->update($this->validatedEntry($request));

        return $this->ok($this->payload($request, $project->fresh()), 'Entry updated.');
    }

    public function deleteEntry(Request $request, LandscapeWorkEntry $landscapeWorkEntry): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        $project = LandscapeProject::findOrFail($landscapeWorkEntry->project_id);
        $this->own($request, $project);
        $this->assertWorking($project);
        $landscapeWorkEntry->delete();

        return $this->ok($this->payload($request, $project->fresh()), 'Entry removed.');
    }

    // ───────── Commission ─────────

    public function storeCommission(Request $request, LandscapeProject $landscapeProject): JsonResponse
    {
        $this->allow($request, 'landscape.finance');
        $this->own($request, $landscapeProject);
        LandscapeCommission::create($this->validatedCommission($request, $landscapeProject) + ['company_id' => $landscapeProject->company_id, 'project_id' => $landscapeProject->id]);

        return $this->created($this->payload($request, $landscapeProject->fresh()), 'Commission added.');
    }

    public function updateCommission(Request $request, LandscapeCommission $landscapeCommission): JsonResponse
    {
        $this->allow($request, 'landscape.finance');
        $project = LandscapeProject::findOrFail($landscapeCommission->project_id);
        $this->own($request, $project);
        if ($landscapeCommission->status === 'paid') {
            throw ValidationException::withMessages(['status' => 'A paid commission cannot be changed.']);
        }
        $landscapeCommission->update($this->validatedCommission($request, $project));

        return $this->ok($this->payload($request, $project->fresh()), 'Commission updated.');
    }

    public function deleteCommission(Request $request, LandscapeCommission $landscapeCommission): JsonResponse
    {
        $this->allow($request, 'landscape.finance');
        $project = LandscapeProject::findOrFail($landscapeCommission->project_id);
        $this->own($request, $project);
        if ($landscapeCommission->status === 'paid') {
            throw ValidationException::withMessages(['status' => 'A paid commission cannot be removed.']);
        }
        $landscapeCommission->delete();

        return $this->ok($this->payload($request, $project->fresh()), 'Commission removed.');
    }

    /** Freezes the amount as worked out now and marks it paid. Record the cash/bank payment in Expenses. */
    public function payCommission(Request $request, LandscapeCommission $landscapeCommission): JsonResponse
    {
        $this->allow($request, 'landscape.finance');
        $project = LandscapeProject::findOrFail($landscapeCommission->project_id);
        $this->own($request, $project);
        if ($project->status !== 'completed') {
            throw ValidationException::withMessages(['status' => 'Commission is paid after the work is completed.']);
        }
        if ($landscapeCommission->status === 'paid') {
            throw ValidationException::withMessages(['status' => 'Already paid.']);
        }
        $report = $this->report($project);
        $amount = $this->commissionAmount($landscapeCommission, $report);
        $landscapeCommission->update(['amount' => $amount, 'status' => 'paid', 'paid_at' => now()]);

        return $this->ok($this->payload($request, $project->fresh()), 'Marked paid. Record the payment in Expenses.');
    }

    // ───────── Internals ─────────

    private const DEFAULT_TERMS = "1. The work is done as per the approved estimate and the scope in this work order.\n2. Any change in scope, area or plants will be charged extra and agreed before it is done.\n3. The advance is adjusted against the final bill; the balance is paid on completion.\n4. Plants are guaranteed only with proper watering and care as advised. Damage by weather, pests or neglect after handing over is not covered.\n5. The client gives access to the site, water and electricity during the work.\n6. Signing below confirms the client has read and accepted this work order.";

    /** @return array<string, mixed> */
    private function payload(Request $request, LandscapeProject $p): array
    {
        $companyId = $p->company_id;
        $p->load(['location:id,name', 'estimates.items', 'entries']);
        $finance = $request->user()->hasPermission('landscape.finance', $companyId);
        $company = $request->attributes->get('company');
        $out = [
            'project'  => $this->projectRow($p),
            'company'  => ['name' => $company?->name, 'legal_name' => $company?->legal_name, 'address' => $company?->address, 'phone' => $company?->phone, 'gst_number' => $company?->gst_number, 'email' => $company?->email],
            'estimates' => $p->estimates->map(fn ($e) => $this->estimateRow($e))->reverse()->values(),
            'entries'  => $p->entries->map(fn ($e) => $this->entryRow($e))->values(),
            'expense_categories' => collect(self::EXPENSE_CATEGORIES)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values(),
            'can'      => ['manage' => $request->user()->hasPermission('landscape.manage', $companyId), 'finance' => $finance],
            'staff'    => User::query()->companyMembers($companyId, 'active')->orderBy('name')->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ];
        $report = $this->report($p);
        // Everyone sees the day-wise work; only finance sees cost, profit and commission.
        $out['report'] = $finance ? $report : array_intersect_key($report, array_flip(['contract_value', 'advance_received', 'balance_due', 'days']));
        if ($finance) {
            $out['commissions'] = $p->commissions()->get()->map(fn ($c) => $this->commissionRow($c, $report))->values();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function report(LandscapeProject $p): array
    {
        $approved = $p->approved_estimate_id ? LandscapeEstimate::find($p->approved_estimate_id) : null;
        $entries = $p->entries()->get();
        $labour = (float) $entries->where('type', 'labour')->sum('amount');
        $expense = (float) $entries->where('type', 'expense')->sum('amount');
        $byCategory = $entries->where('type', 'expense')->groupBy(fn ($e) => $e->category ?: 'other')->map(fn ($g) => round((float) $g->sum('amount'), 2));

        $value = $approved ? (float) $approved->total : 0.0;
        $before = $approved ? (float) $approved->subtotal + (float) $approved->gst_amount : 0.0;
        // GST collected is not income. The discount is on the total, so it reduces the GST share in proportion.
        $gst = ($approved && $before > 0) ? round((float) $approved->gst_amount * $value / $before, 2) : 0.0;
        $net = round($value - $gst, 2);
        $cost = round($labour + $expense, 2);
        $profit = round($net - $cost, 2);

        $days = $entries->groupBy(fn ($e) => $e->work_date->toDateString())->sortKeys()->map(function ($g, $date) {
            return ['date' => $date, 'labour' => round((float) $g->where('type', 'labour')->sum('amount'), 2), 'expense' => round((float) $g->where('type', 'expense')->sum('amount'), 2), 'total' => round((float) $g->sum('amount'), 2)];
        })->values();
        $run = 0.0;
        $days = $days->map(function ($d) use (&$run) {
            $run = round($run + $d['total'], 2);

            return $d + ['running' => $run];
        })->values();

        $paid = (float) $p->commissions()->where('status', 'paid')->sum('amount');
        $pending = 0.0;
        $base = ['profit' => $profit, 'value' => $net];
        foreach ($p->commissions()->where('status', 'pending')->get() as $c) {
            $pending += $this->commissionAmount($c, $base);
        }
        $commissionTotal = round($paid + $pending, 2);

        return [
            'estimate_total' => $value, 'estimate_subtotal' => $approved ? (float) $approved->subtotal : 0.0, 'estimate_gst' => $gst, 'estimate_discount' => $approved ? (float) $approved->discount : 0.0,
            'contract_value' => $value, 'net_revenue' => $net, 'advance_received' => (float) $p->advance_received, 'balance_due' => round($value - (float) $p->advance_received, 2),
            'labour' => round($labour, 2), 'expenses' => round($expense, 2), 'expense_by_category' => $byCategory, 'actual_cost' => $cost,
            'profit' => $profit, 'margin_percent' => $net > 0 ? round($profit / $net * 100, 1) : null,
            'commission' => $commissionTotal, 'profit_after_commission' => round($profit - $commissionTotal, 2),
            'days' => $days, 'work_days' => $days->count(),
        ];
    }

    /** @param array<string, mixed> $r report or ['profit'=>…, 'value'=>…] */
    private function commissionAmount(LandscapeCommission $c, array $r): float
    {
        if ($c->status === 'paid' || $c->basis === 'fixed') {
            return round((float) $c->amount, 2);
        }
        $base = $c->basis === 'value' ? (float) ($r['value'] ?? $r['net_revenue'] ?? 0) : (float) ($r['profit'] ?? 0);

        return round(max(0.0, $base) * (float) $c->percent / 100, 2);
    }

    /** @return array<string, mixed> */
    private function validatedEstimate(Request $request): array
    {
        $data = $request->validate([
            'area_sqft'      => ['nullable', 'numeric', 'min:0'],
            'rate_per_sqft'  => ['nullable', 'numeric', 'min:0'],
            'planting_cost'  => ['nullable', 'numeric', 'min:0'],
            'service_charge' => ['nullable', 'numeric', 'min:0'],
            'other_charge'   => ['nullable', 'numeric', 'min:0'],
            'gst_percent'    => ['nullable', 'numeric', 'min:0', 'max:50'],
            'discount'       => ['nullable', 'numeric', 'min:0'],
            'valid_until'    => ['nullable', 'date'],
            'notes'          => ['nullable', 'string', 'max:3000'],
            'items'          => ['nullable', 'array', 'max:200'],
            'items.*.kind'   => ['required', Rule::in(['plant', 'material', 'other'])],
            'items.*.name'   => ['required', 'string', 'max:160'],
            'items.*.product_id' => ['nullable', 'uuid'],
            'items.*.qty'    => ['required', 'numeric', 'min:0'],
            'items.*.unit'   => ['nullable', 'string', 'max:20'],
            'items.*.rate'   => ['required', 'numeric', 'min:0'],
        ], ['items.*.name.required' => 'Every line needs a name.']);

        $fig = $this->figures($data);
        if ((float) ($data['discount'] ?? 0) > $fig['subtotal'] + $fig['gst_amount'] + 0.005) {
            throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the estimate total.']);
        }

        return $data;
    }

    /**
     * Sums: work charge (area × rate) + plants/material lines + planting cost + service charge + other,
     * then GST, then the discount comes off that total.
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function figures(array $d): array
    {
        $lines = 0.0;
        foreach ($d['items'] ?? [] as $i) {
            $lines += round((float) $i['qty'] * (float) $i['rate'], 2);
        }
        $work = round((float) ($d['area_sqft'] ?? 0) * (float) ($d['rate_per_sqft'] ?? 0), 2);
        $subtotal = round($work + $lines + (float) ($d['planting_cost'] ?? 0) + (float) ($d['service_charge'] ?? 0) + (float) ($d['other_charge'] ?? 0), 2);
        $gst = round($subtotal * (float) ($d['gst_percent'] ?? 0) / 100, 2);
        $discount = round(min((float) ($d['discount'] ?? 0), $subtotal + $gst), 2);

        return [
            'area_sqft' => (float) ($d['area_sqft'] ?? 0), 'rate_per_sqft' => (float) ($d['rate_per_sqft'] ?? 0),
            'planting_cost' => (float) ($d['planting_cost'] ?? 0), 'service_charge' => (float) ($d['service_charge'] ?? 0), 'other_charge' => (float) ($d['other_charge'] ?? 0),
            'gst_percent' => (float) ($d['gst_percent'] ?? 0), 'subtotal' => $subtotal, 'gst_amount' => $gst, 'discount' => $discount,
            'total' => round($subtotal + $gst - $discount, 2),
            'valid_until' => $d['valid_until'] ?? null, 'notes' => $d['notes'] ?? null,
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function saveItems(LandscapeEstimate $est, array $items): void
    {
        $est->items()->delete();
        foreach (array_values($items) as $n => $i) {
            $est->items()->create([
                'kind' => $i['kind'], 'name' => $i['name'], 'product_id' => $i['product_id'] ?? null, 'qty' => (float) $i['qty'],
                'unit' => $i['unit'] ?? null, 'rate' => (float) $i['rate'], 'amount' => round((float) $i['qty'] * (float) $i['rate'], 2), 'sort' => $n,
            ]);
        }
    }

    /** The project shows the amount of its live (latest not-superseded) estimate. */
    private function syncQuote(LandscapeProject $p): void
    {
        if ($p->approved_estimate_id) {
            return;
        }
        $live = LandscapeEstimate::where('project_id', $p->id)->where('status', '!=', 'superseded')->orderByDesc('revision')->first();
        $status = $p->status;
        if ($live && $live->status === 'sent' && $status === 'enquiry') {
            $status = 'quoted';
        } elseif (! $live && $status === 'quoted') {
            $status = 'enquiry';
        }
        $p->update(['quoted_amount' => $live ? $live->total : 0, 'status' => $status]);
    }

    private function estimateMessage(LandscapeProject $p, LandscapeEstimate $e): string
    {
        $m = fn ($n) => '₹'.number_format((float) $n, 2);
        $e->loadMissing('items');
        $company = \App\Models\Company::query()->whereKey($p->company_id)->value('name');
        $lines = ["*Estimate {$e->estimate_no}* — {$company}", "Work: {$p->title} (".(LandscapeProject::SERVICES[$p->service_type] ?? $p->service_type).')'];
        if ($p->site_address) {
            $lines[] = "Site: {$p->site_address}";
        }
        $lines[] = '';
        if ((float) $e->area_sqft > 0 && (float) $e->rate_per_sqft > 0) {
            $lines[] = "Area {$e->area_sqft} sq ft × {$m($e->rate_per_sqft)} = {$m($e->area_sqft * $e->rate_per_sqft)}";
        }
        $plants = $e->items->where('kind', 'plant');
        if ($plants->count()) {
            $lines[] = "Plants: {$plants->sum('qty')} nos ({$plants->count()} kinds) = {$m($plants->sum('amount'))}";
        }
        foreach ($e->items->where('kind', '!=', 'plant') as $i) {
            $lines[] = "{$i->name}: {$m($i->amount)}";
        }
        foreach ([['Planting cost', $e->planting_cost], ['Service charge', $e->service_charge], ['Other', $e->other_charge]] as [$label, $v]) {
            if ((float) $v > 0) {
                $lines[] = "{$label}: {$m($v)}";
            }
        }
        if ((float) $e->gst_amount > 0) {
            $lines[] = "GST {$e->gst_percent}%: {$m($e->gst_amount)}";
        }
        if ((float) $e->discount > 0) {
            $lines[] = "Discount: −{$m($e->discount)}";
        }
        $lines[] = '';
        $lines[] = "*Total: {$m($e->total)}*";
        if ($e->valid_until) {
            $lines[] = 'Valid until '.$e->valid_until->format('d M Y');
        }
        $lines[] = 'Reply to confirm and we will prepare the work order.';

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    private function validatedEntry(Request $request): array
    {
        $data = $request->validate([
            'work_date' => ['required', 'date'],
            'type'      => ['required', Rule::in(['labour', 'expense'])],
            'category'  => ['nullable', Rule::in(array_keys(self::EXPENSE_CATEGORIES))],
            'name'      => ['required', 'string', 'max:160'],
            'qty'       => ['nullable', 'numeric', 'min:0'],
            'rate'      => ['required', 'numeric', 'min:0'],
            'notes'     => ['nullable', 'string', 'max:1000'],
        ], ['name.required' => 'Say who worked, or what the money was spent on.']);
        $qty = $data['type'] === 'labour' ? (float) ($data['qty'] ?? 1) : 1.0;

        return [
            'work_date' => $data['work_date'], 'type' => $data['type'],
            'category' => $data['type'] === 'expense' ? ($data['category'] ?? 'other') : null,
            'name' => $data['name'], 'qty' => $qty, 'rate' => (float) $data['rate'],
            'amount' => round($qty * (float) $data['rate'], 2), 'notes' => $data['notes'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function validatedCommission(Request $request, LandscapeProject $p): array
    {
        $data = $request->validate([
            'user_id'    => ['nullable', 'integer'],
            'staff_name' => ['nullable', 'string', 'max:120'],
            'role'       => ['required', Rule::in(['brought', 'worked', 'supervised'])],
            'basis'      => ['required', Rule::in(['profit', 'value', 'fixed'])],
            'percent'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            'amount'     => ['nullable', 'numeric', 'min:0'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ]);
        $name = $data['staff_name'] ?? null;
        if (filled($data['user_id'] ?? null)) {
            $u = User::query()->companyMembers($p->company_id)->whereKey($data['user_id'])->first();
            abort_unless($u, 422, 'That person does not work in this company.');
            $name = $name ?: $u->name;
        }
        if (! filled($name)) {
            throw ValidationException::withMessages(['staff_name' => 'Choose a staff member or type a name.']);
        }
        if ($data['basis'] === 'fixed' && ! (float) ($data['amount'] ?? 0)) {
            throw ValidationException::withMessages(['amount' => 'Enter the fixed amount.']);
        }
        if ($data['basis'] !== 'fixed' && ! (float) ($data['percent'] ?? 0)) {
            throw ValidationException::withMessages(['percent' => 'Enter the percentage.']);
        }

        return [
            'user_id' => $data['user_id'] ?? null, 'staff_name' => $name, 'role' => $data['role'], 'basis' => $data['basis'],
            'percent' => $data['basis'] === 'fixed' ? null : (float) $data['percent'],
            'amount' => $data['basis'] === 'fixed' ? (float) $data['amount'] : 0, 'notes' => $data['notes'] ?? null,
        ];
    }

    private function assertOpen(LandscapeProject $p): void
    {
        if (in_array($p->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => 'This project is closed.']);
        }
    }

    private function assertWorking(LandscapeProject $p): void
    {
        if (! in_array($p->status, ['approved', 'in_progress'], true)) {
            throw ValidationException::withMessages(['status' => $p->status === 'completed' ? 'The work is completed. Entries can no longer be changed.' : 'Approve the estimate and start the work before adding daily entries.']);
        }
    }

    private function projectOf(Request $request, LandscapeEstimate $e): LandscapeProject
    {
        $p = LandscapeProject::findOrFail($e->project_id);
        $this->own($request, $p);

        return $p;
    }

    private function own(Request $request, LandscapeProject $p): void
    {
        abort_unless((string) $p->company_id === (string) $request->attributes->get('company')->id, 404);
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $request->attributes->get('company')->id), 403);
    }

    /** @return array<string, mixed> */
    private function projectRow(LandscapeProject $p): array
    {
        return [
            'id' => $p->id, 'project_no' => $p->project_no, 'title' => $p->title, 'location' => $p->location?->name,
            'customer_name' => $p->customer_name, 'customer_phone' => $p->customer_phone,
            'service_type' => $p->service_type, 'service' => LandscapeProject::SERVICES[$p->service_type] ?? $p->service_type,
            'site_address' => $p->site_address, 'site_area_sqft' => $p->site_area_sqft !== null ? (float) $p->site_area_sqft : null,
            'visit_date' => optional($p->visit_date)->toDateString(), 'start_date' => optional($p->start_date)->toDateString(), 'end_date' => optional($p->end_date)->toDateString(),
            'quoted_amount' => (float) $p->quoted_amount, 'advance_received' => (float) $p->advance_received, 'status' => $p->status, 'notes' => $p->notes,
            'work_order_no' => $p->work_order_no, 'work_order_date' => optional($p->work_order_date)->toDateString(), 'work_order_terms' => $p->work_order_terms,
            'approved_estimate_id' => $p->approved_estimate_id, 'completed_at' => optional($p->completed_at)->toDateString(), 'completion_note' => $p->completion_note,
        ];
    }

    /** @return array<string, mixed> */
    private function estimateRow(LandscapeEstimate $e): array
    {
        return [
            'id' => $e->id, 'revision' => $e->revision, 'estimate_no' => $e->estimate_no, 'status' => $e->status,
            'area_sqft' => (float) $e->area_sqft, 'rate_per_sqft' => (float) $e->rate_per_sqft,
            'planting_cost' => (float) $e->planting_cost, 'service_charge' => (float) $e->service_charge, 'other_charge' => (float) $e->other_charge,
            'gst_percent' => (float) $e->gst_percent, 'subtotal' => (float) $e->subtotal, 'gst_amount' => (float) $e->gst_amount, 'discount' => (float) $e->discount, 'total' => (float) $e->total,
            'valid_until' => optional($e->valid_until)->toDateString(), 'notes' => $e->notes, 'sent_at' => optional($e->sent_at)->toDateTimeString(), 'created_at' => optional($e->created_at)->toDateString(),
            'items' => $e->items->map(fn ($i) => ['kind' => $i->kind, 'name' => $i->name, 'product_id' => $i->product_id, 'qty' => (float) $i->qty, 'unit' => $i->unit, 'rate' => (float) $i->rate, 'amount' => (float) $i->amount])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function entryRow(LandscapeWorkEntry $e): array
    {
        return ['id' => $e->id, 'work_date' => $e->work_date->toDateString(), 'type' => $e->type, 'category' => $e->category, 'name' => $e->name, 'qty' => (float) $e->qty, 'rate' => (float) $e->rate, 'amount' => (float) $e->amount, 'notes' => $e->notes];
    }

    /** @param array<string, mixed> $report */
    private function commissionRow(LandscapeCommission $c, array $report): array
    {
        return [
            'id' => $c->id, 'user_id' => $c->user_id, 'staff_name' => $c->staff_name, 'role' => $c->role, 'basis' => $c->basis,
            'percent' => $c->percent !== null ? (float) $c->percent : null, 'status' => $c->status, 'paid_at' => optional($c->paid_at)->toDateString(), 'notes' => $c->notes,
            'amount' => $this->commissionAmount($c, ['profit' => $report['profit'], 'value' => $report['net_revenue']]),
        ];
    }
}
