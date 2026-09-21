<?php

namespace App\Http\Requests\Accounting;

use App\Models\AccountingTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountingVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        $voucher = $this->route('accountingTransaction');
        $companyId = $voucher instanceof AccountingTransaction
            ? $voucher->company_id
            : $this->route('current_company')->id;

        return $this->user()->hasPermission('accounts.update', $companyId);
    }

    public function rules(): array
    {
        $voucher = $this->route('accountingTransaction');
        $companyId = $voucher instanceof AccountingTransaction
            ? $voucher->company_id
            : $this->route('current_company')->id;

        return [
            'voucher_date'           => ['required', 'date'],
            'voucher_type'           => ['required', Rule::in(['cash_receipt', 'cash_payment', 'bank_receipt', 'bank_payment', 'contra', 'journal'])],
            'narration'              => ['nullable', 'string', 'max:1000'],
            'amount'                 => ['nullable', 'numeric', 'gt:0'],
            'counterpart_account_id' => ['nullable', 'uuid', Rule::exists('ledger_accounts', 'id')->where('company_id', $companyId)],
            'direction'              => ['nullable', Rule::in(['cash_to_bank', 'bank_to_cash'])],
            'entries'                => ['nullable', 'array', 'min:2'],
            'entries.*.ledger_account_id' => ['required_with:entries', 'uuid', Rule::exists('ledger_accounts', 'id')->where('company_id', $companyId)],
            'entries.*.debit'        => ['nullable', 'numeric', 'min:0'],
            'entries.*.credit'       => ['nullable', 'numeric', 'min:0'],
            'entries.*.narration'    => ['nullable', 'string', 'max:500'],
        ];
    }
}
