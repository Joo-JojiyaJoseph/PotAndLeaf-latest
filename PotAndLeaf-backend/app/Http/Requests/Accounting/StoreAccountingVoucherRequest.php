<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountingVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('accounts.create', $this->writeCompanyId());
    }

    public function rules(): array
    {
        $companyId = $this->writeCompanyId();

        return [
            'voucher_date'           => ['required', 'date'],
            'voucher_type'           => ['required', Rule::in(['cash_receipt', 'cash_payment', 'bank_receipt', 'bank_payment', 'contra', 'journal'])],
            'narration'              => ['nullable', 'string', 'max:1000'],
            'amount'                 => ['nullable', 'numeric', 'gt:0'],
            'counterpart_account_id' => ['nullable', 'uuid', Rule::exists('ledger_accounts', 'id')->where('company_id', $companyId)],
            'direction'              => ['nullable', Rule::in(['cash_to_bank', 'bank_to_cash'])],
            'location_id'            => ['nullable', 'uuid', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'entries'                => ['nullable', 'array', 'min:2'],
            'entries.*.ledger_account_id' => ['required_with:entries', 'uuid', Rule::exists('ledger_accounts', 'id')->where('company_id', $companyId)],
            'entries.*.debit'        => ['nullable', 'numeric', 'min:0'],
            'entries.*.credit'       => ['nullable', 'numeric', 'min:0'],
            'entries.*.narration'    => ['nullable', 'string', 'max:500'],
        ];
    }

    public function writeCompanyId(): int|string
    {
        return $this->route('current_company')->id;
    }
}
