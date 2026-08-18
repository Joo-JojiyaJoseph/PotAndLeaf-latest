<?php

namespace App\Http\Resources;

use App\Models\CommissionPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CommissionPayout */
class CommissionPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'user_id'      => $this->user_id,
            'user_name'    => $this->user?->name,
            'period'       => $this->period,
            'sales_total'  => (float) $this->sales_total,
            'amount'       => (float) $this->amount,
            'mode'         => $this->mode,
            'payment_date' => optional($this->payment_date)->toDateString(),
            'reference'    => $this->reference,
            'notes'        => $this->notes,
            'status'       => $this->status,
        ];
    }
}
