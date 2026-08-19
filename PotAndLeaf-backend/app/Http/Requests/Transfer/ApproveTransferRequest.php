<?php

namespace App\Http\Requests\Transfer;

use App\Models\StockTransfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('transfers.approve', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        /** @var StockTransfer $transfer */
        $transfer = $this->route('stockTransfer');
        $itemIds = $transfer?->items()->pluck('id')->all() ?? [];

        return [
            'approvals'                    => ['nullable', 'array'],
            'approvals.*.id'               => ['required', 'uuid', Rule::in($itemIds)],
            'approvals.*.approved_qty'     => ['required', 'numeric', 'min:0'],
            'approvals.*.rejection_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
