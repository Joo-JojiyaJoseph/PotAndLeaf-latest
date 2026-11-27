<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountingTransaction;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Services\AccountingEngine;
use App\Services\ChartOfAccountsService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Expenses and other receipts, per branch / godown.
 *
 * An entry is a cash- or bank-book voucher (so the books stay right) tagged `source_type = expense`:
 *  - a PAYMENT is money going out (rent, electricity, transport…): Dr expense head, Cr Cash / Bank;
 *  - a RECEIPT is other money coming in (not a sale): Dr Cash / Bank, Cr income head.
 * A person based at one branch or godown can only add and see that location's entries.
 */
class ExpenseController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    /** Heads offered the first time, so the list is never empty. */
    private const DEFAULT_HEADS = [
        'expense' => ['Rent', 'Electricity', 'Water', 'Salaries & wages', 'Transport', 'Repairs & maintenance', 'Stationery & printing', 'Telephone & internet', 'Tea & refreshments', 'Packing material', 'Miscellaneous'],
        'income'  => ['Rent received', 'Interest received', 'Scrap sale', 'Other receipts'],
    ];

    public function __construct(
        private readonly AccountingEngine $engine,
        private readonly ChartOfAccountsService $coa,
    ) {}

    public function formData(Request $request): JsonResponse
    {
        $this->allow($request, 'expenses.view');
        $companyId = $this->company($request)->id;
        $this->seedHeads($companyId);

        $own = $request->user()->locationIdFor($companyId);
        $locations = Location::forCompany($companyId)->where('is_active', true)
            ->when($own, fn ($q) => $q->whereKey($own))
            ->orderBy('name')->get(['id', 'name', 'type', 'is_default'])
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'type' => $l->type, 'is_default' => (bool) $l->is_default]);

        return $this->ok([
            'heads' => [
                'expense' => $this->heads($companyId, 'expense'),
                'income'  => $this->heads($companyId, 'income'),
            ],
            'locations'        => $locations,
            'own_location_id'  => $own,
            'default_location_id' => $own ?: $locations->firstWhere('is_default', true)['id'] ?? ($locations->first()['id'] ?? null),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->allow($request, 'expenses.view');
        $companyId = $this->company($request)->id;
        $own = $request->user()->locationIdFor($companyId);
        $from = \App\Support\Http\DateRange::from($request, now()->startOfMonth()->toDateString());
        $to = \App\Support\Http\DateRange::to($request, now()->toDateString());
        $kind = $request->query('kind');
        $locationId = $own ?: $request->query('location_id');
        $search = trim((string) $request->query('search', ''));

        $query = AccountingTransaction::query()
            ->where('company_id', $companyId)
            ->where('source_type', 'expense')
            ->where('status', 'posted')
            ->whereDate('voucher_date', '>=', $from)->whereDate('voucher_date', '<=', $to)
            ->when(filled($locationId), fn ($q) => $q->where('location_id', $locationId))
            ->when($kind === 'payment', fn ($q) => $q->whereIn('voucher_type', ['cash_payment', 'bank_payment']))
            ->when($kind === 'receipt', fn ($q) => $q->whereIn('voucher_type', ['cash_receipt', 'bank_receipt']))
            ->when(filled($request->query('head_id')), fn ($q) => $q->whereHas('entries', fn ($e) => $e->where('ledger_account_id', $request->query('head_id'))))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('voucher_no', 'like', "%{$search}%")->orWhere('narration', 'like', "%{$search}%")));

        $all = (clone $query)->with(['entries.account:id,name,system_key'])->get();
        $rows = $all->map(fn ($v) => $this->row($v));
        $payments = round($rows->where('kind', 'payment')->sum('amount'), 2);
        $receipts = round($rows->where('kind', 'receipt')->sum('amount'), 2);

        // Totals by head, for the summary strip.
        $byHead = $rows->groupBy(fn ($r) => $r['kind'].'|'.$r['head'])->map(fn ($g) => [
            'kind' => $g->first()['kind'], 'head' => $g->first()['head'], 'amount' => round($g->sum('amount'), 2), 'count' => $g->count(),
        ])->sortByDesc('amount')->values();

        // `all=1` (export) returns every matching row in one go.
        $perPage = $request->boolean('all') ? max(1, $rows->count()) : max(1, min(100, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $sorted = $rows->sortByDesc(fn ($r) => $r['date'].$r['voucher_no'])->values();
        $slice = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        $locNames = Location::forCompany($companyId)->pluck('name', 'id');

        return response()->json([
            'data' => [
                'rows'    => $slice->map(fn ($r) => $r + ['location' => $locNames[$r['location_id']] ?? null]),
                'totals'  => ['payments' => $payments, 'receipts' => $receipts, 'net' => round($receipts - $payments, 2), 'count' => $rows->count()],
                'by_head' => $byHead,
                'can'     => [
                    'create' => $request->user()->hasPermission('expenses.create', $companyId),
                    'delete' => $request->user()->hasPermission('expenses.delete', $companyId),
                ],
            ],
            'meta' => [
                'current_page' => $page, 'last_page' => max(1, (int) ceil($rows->count() / $perPage)),
                'per_page' => $perPage, 'total' => $rows->count(),
                'from' => $rows->isEmpty() ? null : (($page - 1) * $perPage) + 1,
                'to' => $rows->isEmpty() ? null : min($rows->count(), $page * $perPage),
            ],
            'message' => null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->allow($request, 'expenses.create');
        $companyId = $this->company($request)->id;
        $data = $request->validate([
            'date'          => ['required', 'date', 'before_or_equal:today'],
            'kind'          => ['required', 'in:payment,receipt'],
            'mode'          => ['required', 'in:cash,bank'],
            'amount'        => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'head_id'       => ['nullable', 'uuid'],
            'new_head'      => ['nullable', 'string', 'max:80'],
            'location_id'   => ['nullable', 'uuid'],
            'narration'     => ['nullable', 'string', 'max:500'],
        ]);

        $own = $request->user()->locationIdFor($companyId);
        $locationId = app(\App\Services\LocationStockService::class)->resolveLocationId($companyId, $own ?: ($data['location_id'] ?? null), $request->user());

        $type = $data['kind'] === 'payment' ? 'expense' : 'income';
        $head = $this->resolveHead($companyId, $type, $data['head_id'] ?? null, $data['new_head'] ?? null);

        $voucher = $this->engine->post($companyId, [
            'voucher_date'           => $data['date'],
            'voucher_type'           => $data['mode'].'_'.$data['kind'],
            'amount'                 => $data['amount'],
            'counterpart_account_id' => $head->id,
            'location_id'            => $locationId,
            'narration'              => trim(($data['narration'] ?? '') !== '' ? $head->name.' — '.$data['narration'] : $head->name),
            'source_type'            => 'expense',
        ], $request->user()->id);

        return $this->created($this->row($voucher->load('entries.account')), ($data['kind'] === 'payment' ? 'Payment' : 'Receipt').' recorded.');
    }

    public function destroy(Request $request, AccountingTransaction $accountingTransaction): JsonResponse
    {
        $this->allow($request, 'expenses.delete');
        $companyId = $this->company($request)->id;
        abort_unless((string) $accountingTransaction->company_id === (string) $companyId && $accountingTransaction->source_type === 'expense', 404);
        $own = $request->user()->locationIdFor($companyId);
        abort_if($own && (string) $accountingTransaction->location_id !== (string) $own, 403, 'You can only remove entries of your own branch / godown.');

        $this->engine->cancel($accountingTransaction, $request->input('reason'), $request->user()->id);

        return $this->message('Entry removed.');
    }

    /** @return array<string, mixed> */
    private function row(AccountingTransaction $v): array
    {
        $kind = str_ends_with($v->voucher_type, '_payment') ? 'payment' : 'receipt';
        $entry = $v->entries->first(fn ($e) => $kind === 'payment' ? (float) $e->debit > 0 : (float) $e->credit > 0);

        return [
            'id'          => $v->id,
            'voucher_no'  => $v->voucher_no,
            'date'        => optional($v->voucher_date)->toDateString(),
            'kind'        => $kind,
            'mode'        => str_starts_with($v->voucher_type, 'cash') ? 'cash' : 'bank',
            'amount'      => round((float) ($kind === 'payment' ? $entry?->debit : $entry?->credit), 2),
            'head'        => $entry?->account?->name,
            'head_id'     => $entry?->ledger_account_id,
            'narration'   => $v->narration,
            'location_id' => $v->location_id,
        ];
    }

    private function resolveHead(int|string $companyId, string $type, ?string $headId, ?string $newName): LedgerAccount
    {
        if (filled($newName)) {
            $name = trim($newName);
            $existing = LedgerAccount::forCompany($companyId)->where('account_type', $type)->whereNull('system_key')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

            return $existing ?? $this->createHead($companyId, $type, $name);
        }
        if (! filled($headId)) {
            throw ValidationException::withMessages(['head_id' => 'Choose a head or type a new one.']);
        }
        $head = LedgerAccount::forCompany($companyId)->where('account_type', $type)->whereKey($headId)->first();
        if (! $head) {
            throw ValidationException::withMessages(['head_id' => 'That head was not found for this company.']);
        }

        return $head;
    }

    private function createHead(int|string $companyId, string $type, string $name): LedgerAccount
    {
        $prefix = $type === 'expense' ? 'EXP' : 'INC';
        $next = (int) LedgerAccount::forCompany($companyId)->where('code', 'like', $prefix.'-%')->count() + 1;
        do {
            $code = sprintf('%s-%03d', $prefix, $next++);
        } while (LedgerAccount::forCompany($companyId)->where('code', $code)->exists());

        return LedgerAccount::create([
            'company_id' => $companyId, 'code' => $code, 'name' => $name,
            'account_type' => $type, 'opening_balance' => 0, 'status' => 'active',
        ]);
    }

    private function seedHeads(int|string $companyId): void
    {
        $this->coa->ensureForCompany($companyId);
        foreach (self::DEFAULT_HEADS as $type => $names) {
            // Only once per company and kind: when it has no custom head yet.
            if (LedgerAccount::forCompany($companyId)->where('account_type', $type)->whereNull('system_key')->exists()) {
                continue;
            }
            foreach ($names as $name) {
                $this->createHead($companyId, $type, $name);
            }
        }
    }

    /** @return array<int, array<string, string>> */
    private function heads(int|string $companyId, string $type): array
    {
        return LedgerAccount::forCompany($companyId)->active()->where('account_type', $type)->whereNull('system_key')
            ->orderBy('name')->get(['id', 'name'])->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->all();
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }
}
