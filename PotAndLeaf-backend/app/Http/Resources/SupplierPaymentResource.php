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
            'mode'          => $this->mode,
            'reference'     => $this->reference,
            'notes'         => $this->notes,
        ];
    }
}
