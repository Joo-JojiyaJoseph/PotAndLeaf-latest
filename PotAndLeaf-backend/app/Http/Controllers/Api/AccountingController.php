<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreAccountingVoucherRequest;
use App\Http\Requests\Accounting\UpdateAccountingVoucherRequest;
use App\Http\Resources\AccountingTransactionResource;
use App\Models\AccountingTransaction;
use App\Services\AccountingEngine;
use App\Services\ChartOfAccountsService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(
        private readonly AccountingEngine $engine,
        private readonly ChartOfAccountsService $coa,
    ) {}

    public function formData(Request $request): JsonResponse
    {
        $this->allow($request, 'accounts.view');
        $companyId = $this->bookCompanyId($request);
        $accounts = $this->coa->accounts($companyId);

        return $this->ok([
            'accounts' => $accounts->map(fn ($a) => [
                'id'           => $a->id,
                'code'         => $a->code,
                'name'         => $a->name,
                'account_type' => $a->account_type,
                'system_key'   => $a->system_key,
            ])->values(),
            'voucher_types' => [
                'cash' => [
                    ['value' => 'cash_receipt', 'label' => 'Cash receipt'],
                    ['value' => 'cash_payment', 'label' => 'Cash payment'],
                    ['value' => 'contra', 'label' => 'Contra (cash ↔ bank)'],
                ],
                'bank' => [
                    ['value' => 'bank_receipt', 'label' => 'Bank receipt'],
                    ['value' => 'bank_payment', 'label' => 'Bank payment'],
                    ['value' => 'contra', 'label' => 'Contra (cash ↔ bank)'],
                ],
                'journal' => [
                    ['value' => 'journal', 'label' => 'Journal voucher'],
                ],
            ],
        ]);
    }

    public function cashBook(Request $request): JsonResponse
    {
        return $this->book($request, 'cash');
    }

    public function bankBook(Request $request): JsonResponse
    {
        return $this->book($request, 'bank');
    }

    public function journal(Request $request): JsonResponse
    {
        return $this->book($request, 'journal');
    }

    public function show(Request $request, AccountingTransaction $accountingTransaction): JsonResponse
    {
        $this->allow($request, 'accounts.view');
        abort_unless(
            $request->user()->is_super_admin
                || (string) $accountingTransaction->company_id === (string) $this->company($request)->id,
            404,
        );

        return $this->ok(new AccountingTransactionResource($this->engine->show($accountingTransaction)));
    }

    public function store(StoreAccountingVoucherRequest $request): JsonResponse
    {
        $voucher = $this->engine->post(
            $request->writeCompanyId(),
            $request->validated(),
            $request->user()?->id,
        );

        return $this->created(new AccountingTransactionResource($voucher), 'Entry posted.');
    }

    public function update(UpdateAccountingVoucherRequest $request, AccountingTransaction $accountingTransaction): JsonResponse
    {
        $this->assertCompany($request, $accountingTransaction);
        $voucher = $this->engine->update($accountingTransaction, $request->validated(), $request->user()?->id);

        return $this->ok(new AccountingTransactionResource($voucher), 'Entry updated.');
    }

    public function destroy(Request $request, AccountingTransaction $accountingTransaction): JsonResponse
    {
        $this->allow($request, 'accounts.delete');
        $this->assertCompany($request, $accountingTransaction);
        $this->engine->cancel($accountingTransaction, $request->input('reason'), $request->user()?->id);

        return $this->message('Entry cancelled.');
    }

    private function book(Request $request, string $book): JsonResponse
    {
        $this->allow($request, 'accounts.view');
        $from = $request->query('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        $payload = $this->engine->book(
            $this->bookCompanyId($request),
            $book,
            $from,
            $to,
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 50),
        );

        $meta = $payload['meta'];
        unset($payload['meta']);

        return response()->json([
            'data'    => $payload,
            'meta'    => $meta,
            'message' => null,
        ]);
    }

    private function bookCompanyId(Request $request): int|string
    {
        return $this->listCompanyId($request) ?? $this->company($request)->id;
    }

    private function assertCompany(Request $request, AccountingTransaction $voucher): void
    {
        abort_unless((string) $voucher->company_id === (string) $this->company($request)->id, 404);
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }
}
