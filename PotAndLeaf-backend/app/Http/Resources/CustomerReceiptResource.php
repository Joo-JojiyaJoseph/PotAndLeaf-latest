<?php

namespace App\Http\Resources;

use App\Models\CustomerReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerReceipt */
class CustomerReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'receipt_no'    => $this->receipt_no,
            'receipt_date'  => optional($this->receipt_date)->toDateString(),
            'customer_id'   => $this->customer_id,
            'customer_name' => $this->customer?->name,
            'sale_id'       => $this->sale_id,
            'sale_no'       => $this->sale?->sale_no,
            'amount'        => (float) $this->amount,
            'mode'          => $this->mode,
            'reference'     => $this->reference,
            'notes'         => $this->notes,
            'is_advance'    => (bool) $this->is_advance,
            'applied_from_advance' => (bool) $this->applied_from_advance,
            'allocations'   => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'sale_id' => $a->sale_id,
                'sale_no' => $a->sale?->sale_no,
                'amount'  => (float) $a->amount,
            ])->values()),
        ];
    }
}
