<?php

namespace App\Http\Resources;

use App\Models\SalesReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalesReturn */
class SalesReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $companyId = $this->company_id;

        return [
            'id'            => $this->id,
            'return_no'     => $this->return_no,
            'return_date'   => optional($this->return_date)->toDateString(),
            'is_interstate' => (bool) $this->is_interstate,
            'reason'        => $this->reason,
            'subtotal'      => (float) $this->subtotal,
            'tax_total'     => (float) $this->tax_total,
            'round_off'     => (float) $this->round_off,
            'grand_total'   => (float) $this->grand_total,
            'status'        => $this->status,
            'notes'         => $this->notes,
            'confirmed_at'  => optional($this->confirmed_at)->toDateTimeString(),
            'sale'          => $this->whenLoaded('sale', fn () => [
                'id'      => $this->sale?->id,
                'sale_no' => $this->sale?->sale_no,
            ]),
            'customer'      => $this->whenLoaded('customer', fn () => [
                'id'            => $this->customer?->id,
                'name'          => $this->customer?->name,
                'customer_code' => $this->customer?->customer_code,
            ]),
            'items'         => SalesReturnItemResource::collection($this->whenLoaded('items')),
            'can'           => [
                'confirm' => $this->status === 'draft' && $user?->hasPermission('sales_returns.confirm', $companyId),
                'cancel'  => $this->status !== 'cancelled' && $user?->hasPermission('sales_returns.delete', $companyId),
            ],
        ];
    }
}
