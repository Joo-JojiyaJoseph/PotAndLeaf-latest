<?php

namespace App\Http\Resources;

use App\Models\SupplierPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SupplierPayment */
class SupplierPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'payment_no'    => $this->payment_no,
            'payment_date'  => optional($this->payment_date)->toDateString(),
            'supplier_id'   => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'purchase_id'   => $this->purchase_id,
            'purchase_no'   => $this->purchase?->purchase_no,
            'amount'        => (float) $this->amount,
            'adjusted_amount' => (float) ($this->adjusted_amount ?? 0),
            'remaining_advance' => $this->is_advance
                ? round((float) $this->amount - (float) ($this->adjusted_amount ?? 0), 2)
                : null,
            'mode'          => $this->mode,
            'reference'     => $this->reference,
            'notes'         => $this->notes,
            'is_advance'    => (bool) $this->is_advance,
            'applied_from_advance' => (bool) $this->applied_from_advance,
            'allocations'   => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'purchase_id' => $a->purchase_id,
                'purchase_no' => $a->purchase?->purchase_no,
                'amount'      => (float) $a->amount,
            ])->values()),
        ];
    }
}
