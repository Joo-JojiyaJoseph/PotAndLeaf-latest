<?php

namespace App\Http\Resources;

use App\Models\PurchaseReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseReturn */
class PurchaseReturnResource extends JsonResource
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
            'grand_total'   => (float) $this->grand_total,
            'status'        => $this->status,
            'notes'         => $this->notes,
            'confirmed_at'  => optional($this->confirmed_at)->toDateTimeString(),
            'purchase'      => $this->whenLoaded('purchase', fn () => [
                'id'          => $this->purchase?->id,
                'purchase_no' => $this->purchase?->purchase_no,
            ]),
            'supplier'      => $this->whenLoaded('supplier', fn () => [
                'id'            => $this->supplier?->id,
                'name'          => $this->supplier?->name,
                'supplier_code' => $this->supplier?->supplier_code,
            ]),
            'items'         => PurchaseReturnItemResource::collection($this->whenLoaded('items')),
            'can'           => [
                'confirm' => $this->status === 'draft' && $user?->hasPermission('purchase_returns.confirm', $companyId),
                'cancel'  => $this->status !== 'cancelled' && $user?->hasPermission('purchase_returns.delete', $companyId),
            ],
        ];
    }
}
