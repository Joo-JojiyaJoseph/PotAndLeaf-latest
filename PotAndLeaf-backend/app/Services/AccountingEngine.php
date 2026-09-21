<?php

namespace App\Services;

use App\Models\AccountingEntry;
use App\Models\AccountingTransaction;
use App\Models\CommissionPayout;
use App\Models\CustomerReceipt;
use App\Models\LedgerAccount;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AccountingEngine
{
    public const TYPES = [
        'cash_receipt' => 'cash',
        'cash_payment' => 'cash',
        'bank_receipt' => 'bank',
        'bank_payment' => 'bank',
        'contra'       => 'contra',
        'journal'      => 'journal',
    ];

    public const PREFIX = [
        'cash_receipt' => 'CB-',
        'cash_payment' => 'CB-',
        'bank_receipt' => 'BB-',
        'bank_payment' => 'BB-',
        'contra'       => 'CN-',
        'journal'      => 'JV-',
    ];

    public function __construct(private readonly ChartOfAccountsService $coa) {}

    public function ready(): bool
    {
        return Schema::hasTable('ledger_accounts')
            && Schema::hasTable('accounting_transactions')
            && Schema::hasTable('accounting_entries');
    }

    /** Post any receipts/payments/commission not yet in the books. */
    public function backfillCompany(int|string $companyId): void
    {
        if (! $this->ready()) {
            return;
        }

        $this->coa->ensureForCompany($companyId);

        $postedReceipts = AccountingTransaction::forCompany($companyId)
            ->where('source_type', 'customer_receipt')
            ->pluck('source_id');
        CustomerReceipt::forCompany($companyId)
            ->when($postedReceipts->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $postedReceipts))
            ->orderBy('receipt_date')
            ->get()
            ->each(fn (CustomerReceipt $r) => $this->postReceipt($r));

        $postedPayments = AccountingTransaction::forCompany($companyId)
            ->where('source_type', 'supplier_payment')
            ->pluck('source_id');
        SupplierPayment::forCompany($companyId)
            ->when($postedPayments->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $postedPayments))
            ->orderBy('payment_date')
            ->get()
            ->each(fn (SupplierPayment $p) => $this->postPayment($p));

        $postedCommission = AccountingTransaction::forCompany($companyId)
            ->where('source_type', 'commission_payout')
            ->pluck('source_id');
        CommissionPayout::forCompany($companyId)
            ->where('status', 'paid')
            ->when($postedCommission->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $postedCommission))
            ->orderBy('payment_date')
            ->get()
            ->each(fn (CommissionPayout $p) => $this->postCommission($p));
    }

    public function post(int|string $companyId, array $data, ?int $userId = null): AccountingTransaction
    {
        return DB::transaction(function () use ($companyId, $data, $userId) {
            $this->coa->ensureForCompany($companyId);

            $type = (string) ($data['voucher_type'] ?? '');
            if (! isset(self::TYPES[$type])) {
                throw ValidationException::withMessages(['voucher_type' => 'Unknown voucher type.']);
            }

            $entries = $this->resolveEntries($companyId, $type, $data);
            $this->assertBalanced($entries);
            $this->assertTouchesBook($companyId, $type, $entries);

            $voucher = AccountingTransaction::create([
                'company_id'   => $companyId,
                'location_id'  => $data['location_id'] ?? null,
                'voucher_no'   => $this->nextVoucherNo($companyId, self::PREFIX[$type]),
                'voucher_date' => $data['voucher_date'],
                'voucher_type' => $type,
                'book'         => self::TYPES[$type],
                'source_type'  => $data['source_type'] ?? 'manual',
                'source_id'    => $data['source_id'] ?? null,
                'narration'    => $data['narration'] ?? null,
                'status'       => 'posted',
                'created_by'   => $userId,
            ]);

            $this->writeEntries($voucher, $entries);

            return $voucher->load('entries.account');
        });
    }

    public function update(AccountingTransaction $voucher, array $data, ?int $userId = null): AccountingTransaction
    {
        return DB::transaction(function () use ($voucher, $data, $userId) {
            $voucher = AccountingTransaction::lockForUpdate()->findOrFail($voucher->id);
            $this->assertEditable($voucher);

            $type = (string) ($data['voucher_type'] ?? $voucher->voucher_type);
            if (! isset(self::TYPES[$type])) {
                throw ValidationException::withMessages(['voucher_type' => 'Unknown voucher type.']);
            }

            $entries = $this->resolveEntries($voucher->company_id, $type, $data);
            $this->assertBalanced($entries);
            $this->assertTouchesBook($voucher->company_id, $type, $entries);

            $voucher->fill([
                'voucher_date' => $data['voucher_date'] ?? $voucher->voucher_date,
                'voucher_type' => $type,
                'book'         => self::TYPES[$type],
                'narration'    => array_key_exists('narration', $data) ? $data['narration'] : $voucher->narration,
                'updated_by'   => $userId,
            ])->save();

            $voucher->entries()->delete();
            $this->writeEntries($voucher, $entries);

            return $voucher->fresh()->load('entries.account');
        });
    }

    public function cancel(AccountingTransaction $voucher, ?string $reason = null, ?int $userId = null): AccountingTransaction
    {
        return DB::transaction(function () use ($voucher, $reason, $userId) {
            $voucher = AccountingTransaction::lockForUpdate()->findOrFail($voucher->id);
            if (! $voucher->isPosted()) {
                throw ValidationException::withMessages(['status' => 'Only posted entries can be cancelled.']);
            }
            if (! $voucher->isManual()) {
                throw ValidationException::withMessages([
                    'source_type' => 'This entry was posted from a receipt or payment. Void it there instead.',
                ]);
            }

            $voucher->status = 'cancelled';
            $voucher->cancellation_reason = $reason;
            $voucher->cancelled_by = $userId;
            $voucher->cancelled_at = now();
            $voucher->save();

            return $voucher->fresh()->load('entries.account');
        });
    }

    public function cancelBySource(string $sourceType, string $sourceId): void
    {
        if (! $this->ready()) {
            return;
        }

        AccountingTransaction::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', 'posted')
            ->get()
            ->each(function (AccountingTransaction $voucher) {
                $voucher->status = 'cancelled';
                $voucher->cancellation_reason = 'Source document voided';
                $voucher->cancelled_at = now();
                $voucher->save();
            });
    }

    public function postReceipt(CustomerReceipt $receipt): ?AccountingTransaction
    {
        if (! $this->ready() || $receipt->applied_from_advance) {
            return null;
        }

        $isCash = ($receipt->mode ?? 'cash') === 'cash';

        return $this->postFromSource(
            $receipt->company_id,
            'customer_receipt',
            $receipt->id,
            $isCash ? 'cash_receipt' : 'bank_receipt',
            optional($receipt->receipt_date)->toDateString() ?? now()->toDateString(),
            (float) $receipt->amount,
            $isCash ? 'cash' : 'bank',
            ($receipt->is_advance || $receipt->advance_order_id) ? 'customer_advance' : 'ar',
            $receipt->notes ?: "Receipt {$receipt->receipt_no}",
        );
    }

    public function postPayment(SupplierPayment $payment): ?AccountingTransaction
    {
        if (! $this->ready() || $payment->applied_from_advance) {
            return null;
        }

        $isCash = ($payment->mode ?? 'cash') === 'cash';

        return $this->postFromSource(
            $payment->company_id,
            'supplier_payment',
            $payment->id,
            $isCash ? 'cash_payment' : 'bank_payment',
            optional($payment->payment_date)->toDateString() ?? now()->toDateString(),
            (float) $payment->amount,
            ($payment->is_advance) ? 'supplier_advance' : 'ap',
            $isCash ? 'cash' : 'bank',
            $payment->notes ?: "Payment {$payment->payment_no}",
        );
    }

    public function postCommission(CommissionPayout $payout): ?AccountingTransaction
    {
        if (! $this->ready() || $payout->status !== 'paid' || (float) $payout->amount <= 0) {
            return null;
        }

        $isCash = ($payout->mode ?? 'cash') === 'cash';

        return $this->postFromSource(
            $payout->company_id,
            'commission_payout',
            $payout->id,
            $isCash ? 'cash_payment' : 'bank_payment',
            optional($payout->payment_date)->toDateString() ?? now()->toDateString(),
            (float) $payout->amount,
            'other_expense',
            $isCash ? 'cash' : 'bank',
            $payout->notes ?: 'Commission payout '.($payout->period ?? ''),
        );
    }

    public function postFromSource(
        int|string $companyId,
        string $sourceType,
        string $sourceId,
        string $voucherType,
        string $voucherDate,
        float $amount,
        string $debitKey,
        string $creditKey,
        ?string $narration = null,
    ): ?AccountingTransaction {
        if (! $this->ready()) {
            return null;
        }

        $existing = AccountingTransaction::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', 'posted')
            ->first();
        if ($existing) {
            return $existing->load('entries.account');
        }

        $debit = $this->coa->systemAccount($companyId, $debitKey);
        $credit = $this->coa->systemAccount($companyId, $creditKey);

        return $this->post($companyId, [
            'voucher_type' => $voucherType,
            'voucher_date' => $voucherDate,
            'narration'    => $narration,
            'source_type'  => $sourceType,
            'source_id'    => $sourceId,
            'entries'      => [
                ['ledger_account_id' => $debit->id,  'debit' => $amount, 'credit' => 0],
                ['ledger_account_id' => $credit->id, 'debit' => 0,       'credit' => $amount],
            ],
        ]);
    }

    /**
     * Cash/bank running-balance book, or journal voucher list.
     *
     * @return array{opening:float,closing:float,receipts:float,payments:float,rows:array,meta:array}
     */
    public function book(int|string $companyId, string $book, string $from, string $to, int $page = 1, int $perPage = 50): array
    {
        $from = substr($from, 0, 10);
        $to = substr($to, 0, 10);
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);

        if (! $this->ready()) {
            return [
                'book' => $book, 'account' => null, 'opening' => 0, 'closing' => 0,
                'receipts' => 0, 'payments' => 0, 'from' => $from, 'to' => $to, 'rows' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0, 'from' => null, 'to' => null],
            ];
        }

        $this->backfillCompany($companyId);
        $this->coa->ensureForCompany($companyId);

        if ($book === 'journal') {
            return $this->journalBook($companyId, $from, $to, $page, $perPage);
        }

        $key = $book === 'bank' ? 'bank' : 'cash';
        $account = $this->coa->systemAccount($companyId, $key);
        $settingKey = $key === 'cash' ? 'cash_opening_balance' : 'bank_opening_balance';
        $opening = (float) app(SettingsService::class)->get($companyId, $settingKey, '0');

        $prior = AccountingEntry::query()
            ->where('company_id', $companyId)
            ->where('ledger_account_id', $account->id)
            ->whereHas('voucher', fn ($q) => $q->posted()->whereDate('voucher_date', '<', $from))
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();

        $opening = round($opening + (float) $prior->d - (float) $prior->c, 2);

        $lines = AccountingEntry::query()
            ->where('company_id', $companyId)
            ->where('ledger_account_id', $account->id)
            ->whereHas('voucher', fn ($q) => $q->posted()->whereDate('voucher_date', '>=', $from)->whereDate('voucher_date', '<=', $to))
            ->with(['voucher'])
            ->get()
            ->sortBy(fn (AccountingEntry $e) => $e->voucher->voucher_date->toDateString().'|'.$e->voucher->voucher_no)
            ->values();

        $balance = $opening;
        $receipts = 0.0;
        $payments = 0.0;
        $rows = [];
        foreach ($lines as $line) {
            $debit = (float) $line->debit;
            $credit = (float) $line->credit;
            $balance = round($balance + $debit - $credit, 2);
            $receipts += $debit;
            $payments += $credit;
            $v = $line->voucher;
            $rows[] = [
                'id'            => $v->id,
                'entry_id'      => $line->id,
                'voucher_no'    => $v->voucher_no,
                'voucher_date'  => $v->voucher_date->toDateString(),
                'voucher_type'  => $v->voucher_type,
                'book'          => $v->book,
                'narration'     => $line->narration ?: $v->narration,
                'source_type'   => $v->source_type,
                'source_id'     => $v->source_id,
                'is_manual'     => $v->isManual(),
                'status'        => $v->status,
                'debit'         => round($debit, 2),
                'credit'        => round($credit, 2),
                'balance'       => $balance,
            ];
        }

        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'book'     => $key,
            'account'  => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name],
            'opening'  => $opening,
            'closing'  => $balance,
            'receipts' => round($receipts, 2),
            'payments' => round($payments, 2),
            'from'     => $from,
            'to'       => $to,
            'rows'     => array_values($slice),
            'meta'     => [
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
                'per_page'     => $perPage,
                'total'        => $total,
                'from'         => $total === 0 ? null : (($page - 1) * $perPage) + 1,
                'to'           => $total === 0 ? null : min($total, $page * $perPage),
            ],
        ];
    }

    public function show(AccountingTransaction $voucher): AccountingTransaction
    {
        return $voucher->load('entries.account');
    }

    private function journalBook(int|string $companyId, string $from, string $to, int $page, int $perPage): array
    {
        $query = AccountingTransaction::forCompany($companyId)
            ->posted()
            ->where('book', 'journal')
            ->whereDate('voucher_date', '>=', $from)
            ->whereDate('voucher_date', '<=', $to)
            ->with('entries.account')
            ->orderBy('voucher_date')
            ->orderBy('voucher_no');

        $all = $query->get();
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $rows = [];
        foreach ($all as $v) {
            $d = $v->debitTotal();
            $c = $v->creditTotal();
            $totalDebit += $d;
            $totalCredit += $c;
            $rows[] = [
                'id'           => $v->id,
                'voucher_no'   => $v->voucher_no,
                'voucher_date' => $v->voucher_date->toDateString(),
                'voucher_type' => $v->voucher_type,
                'book'         => $v->book,
                'narration'    => $v->narration,
                'source_type'  => $v->source_type,
                'source_id'    => $v->source_id,
                'is_manual'    => $v->isManual(),
                'status'       => $v->status,
                'debit'        => $d,
                'credit'       => $c,
                'balance'      => null,
                'entries'      => $v->entries->map(fn (AccountingEntry $e) => [
                    'ledger_account_id' => $e->ledger_account_id,
                    'account_code'      => $e->account?->code,
                    'account_name'      => $e->account?->name,
                    'debit'             => (float) $e->debit,
                    'credit'            => (float) $e->credit,
                    'narration'         => $e->narration,
                ])->values()->all(),
            ];
        }

        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'book'     => 'journal',
            'account'  => null,
            'opening'  => 0,
            'closing'  => 0,
            'receipts' => round($totalDebit, 2),
            'payments' => round($totalCredit, 2),
            'from'     => $from,
            'to'       => $to,
            'rows'     => array_values($slice),
            'meta'     => [
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
                'per_page'     => $perPage,
                'total'        => $total,
                'from'         => $total === 0 ? null : (($page - 1) * $perPage) + 1,
                'to'           => $total === 0 ? null : min($total, $page * $perPage),
            ],
        ];
    }

    private function resolveEntries(int|string $companyId, string $type, array $data): array
    {
        if (! empty($data['entries']) && is_array($data['entries'])) {
            return array_values($data['entries']);
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        $cash = $this->coa->systemAccount($companyId, 'cash');
        $bank = $this->coa->systemAccount($companyId, 'bank');

        if ($type === 'contra') {
            $direction = $data['direction'] ?? 'cash_to_bank';
            if ($direction === 'bank_to_cash') {
                return [
                    ['ledger_account_id' => $cash->id, 'debit' => $amount, 'credit' => 0],
                    ['ledger_account_id' => $bank->id, 'debit' => 0, 'credit' => $amount],
                ];
            }

            return [
                ['ledger_account_id' => $bank->id, 'debit' => $amount, 'credit' => 0],
                ['ledger_account_id' => $cash->id, 'debit' => 0, 'credit' => $amount],
            ];
        }

        $counterId = $data['counterpart_account_id'] ?? null;
        if (! $counterId) {
            throw ValidationException::withMessages(['counterpart_account_id' => 'Select the other account.']);
        }

        $counter = LedgerAccount::forCompany($companyId)->find($counterId);
        if (! $counter) {
            throw ValidationException::withMessages(['counterpart_account_id' => 'Account not found in this company.']);
        }

        return match ($type) {
            'cash_receipt' => [
                ['ledger_account_id' => $cash->id, 'debit' => $amount, 'credit' => 0],
                ['ledger_account_id' => $counter->id, 'debit' => 0, 'credit' => $amount],
            ],
            'cash_payment' => [
                ['ledger_account_id' => $counter->id, 'debit' => $amount, 'credit' => 0],
                ['ledger_account_id' => $cash->id, 'debit' => 0, 'credit' => $amount],
            ],
            'bank_receipt' => [
                ['ledger_account_id' => $bank->id, 'debit' => $amount, 'credit' => 0],
                ['ledger_account_id' => $counter->id, 'debit' => 0, 'credit' => $amount],
            ],
            'bank_payment' => [
                ['ledger_account_id' => $counter->id, 'debit' => $amount, 'credit' => 0],
                ['ledger_account_id' => $bank->id, 'debit' => 0, 'credit' => $amount],
            ],
            default => throw ValidationException::withMessages(['entries' => 'Journal entries must include debit and credit lines.']),
        };
    }

    private function assertBalanced(array $entries): void
    {
        if (count($entries) < 2) {
            throw ValidationException::withMessages(['entries' => 'At least two ledger lines are required.']);
        }

        $debit = 0.0;
        $credit = 0.0;
        foreach ($entries as $i => $row) {
            $d = round((float) ($row['debit'] ?? 0), 2);
            $c = round((float) ($row['credit'] ?? 0), 2);
            if ($d < 0 || $c < 0) {
                throw ValidationException::withMessages(["entries.$i" => 'Amounts cannot be negative.']);
            }
            if ($d > 0 && $c > 0) {
                throw ValidationException::withMessages(["entries.$i.debit" => 'A line cannot have both debit and credit.']);
            }
            if ($d <= 0 && $c <= 0) {
                throw ValidationException::withMessages(["entries.$i.debit" => 'Each line needs a debit or a credit.']);
            }
            if (blank($row['ledger_account_id'] ?? null)) {
                throw ValidationException::withMessages(["entries.$i.ledger_account_id" => 'Select an account.']);
            }
            $debit += $d;
            $credit += $c;
        }

        if (abs(round($debit, 2) - round($credit, 2)) > 0.005) {
            throw ValidationException::withMessages(['entries' => 'Debit and credit must be equal.']);
        }
    }

    private function assertTouchesBook(int|string $companyId, string $type, array $entries): void
    {
        $ids = collect($entries)->pluck('ledger_account_id')->map(fn ($id) => (string) $id);
        $cashId = (string) $this->coa->systemAccount($companyId, 'cash')->id;
        $bankId = (string) $this->coa->systemAccount($companyId, 'bank')->id;

        if (in_array($type, ['cash_receipt', 'cash_payment'], true) && ! $ids->contains($cashId)) {
            throw ValidationException::withMessages(['entries' => 'Cash book entries must include the Cash account.']);
        }
        if (in_array($type, ['bank_receipt', 'bank_payment'], true) && ! $ids->contains($bankId)) {
            throw ValidationException::withMessages(['entries' => 'Bank book entries must include the Bank account.']);
        }
        if ($type === 'contra' && (! $ids->contains($cashId) || ! $ids->contains($bankId))) {
            throw ValidationException::withMessages(['entries' => 'Contra must move money between Cash and Bank.']);
        }
    }

    private function assertEditable(AccountingTransaction $voucher): void
    {
        if (! $voucher->isPosted()) {
            throw ValidationException::withMessages(['status' => 'Cancelled entries cannot be edited.']);
        }
        if (! $voucher->isManual()) {
            throw ValidationException::withMessages([
                'source_type' => 'This entry was posted from a receipt or payment. Edit it there instead.',
            ]);
        }
    }

    private function writeEntries(AccountingTransaction $voucher, array $entries): void
    {
        foreach ($entries as $row) {
            $voucher->entries()->create([
                'company_id'        => $voucher->company_id,
                'ledger_account_id' => $row['ledger_account_id'],
                'debit'             => round((float) ($row['debit'] ?? 0), 2),
                'credit'            => round((float) ($row['credit'] ?? 0), 2),
                'narration'         => $row['narration'] ?? null,
            ]);
        }
    }

    private function nextVoucherNo(int|string $companyId, string $prefix): string
    {
        $count = AccountingTransaction::withTrashed()
            ->forCompany($companyId)
            ->where('voucher_no', 'like', $prefix.'%')
            ->count();

        return $prefix.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
