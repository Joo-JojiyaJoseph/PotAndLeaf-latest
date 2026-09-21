<?php

namespace App\Http\Resources;

use App\Models\AccountingTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccountingTransaction */
class AccountingTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('entries.account');

        return [
            'id'           => $this->id,
            'company_id'   => $this->company_id,
            'voucher_no'   => $this->voucher_no,
            'voucher_date' => optional($this->voucher_date)->toDateString(),
            'voucher_type' => $this->voucher_type,
            'book'         => $this->book,
            'source_type'  => $this->source_type,
            'source_id'    => $this->source_id,
            'narration'    => $this->narration,
            'status'       => $this->status,
            'is_manual'    => $this->isManual(),
            'debit_total'  => $this->debitTotal(),
            'credit_total' => $this->creditTotal(),
            'entries'      => $this->entries->map(fn ($e) => [
                'id'                => $e->id,
                'ledger_account_id' => $e->ledger_account_id,
                'account_code'      => $e->account?->code,
                'account_name'      => $e->account?->name,
                'account_type'      => $e->account?->account_type,
                'system_key'        => $e->account?->system_key,
                'debit'             => (float) $e->debit,
                'credit'            => (float) $e->credit,
                'narration'         => $e->narration,
            ])->values(),
        ];
    }
}
