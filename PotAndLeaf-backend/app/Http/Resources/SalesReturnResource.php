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
            'company_id'    => $this->company_id,
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
            'refund_mode'   => $this->refund_mode ?? 'advance',
            'advance_credit'   => (float) ($this->advance_credit ?? 0),
            'advance_refunded' => (float) ($this->advance_refunded ?? 0),
            // Held as advance and not yet given back: this is what "Mark refund given" can pay out.
            'refundable'    => round(max(0, (float) ($this->advance_credit ?? 0) - (float) ($this->advance_refunded ?? 0)), 2),
            'refunds'       => $this->whenLoaded('refunds', fn () => $this->refunds->map(fn ($r) => [
                'id' => $r->id, 'refund_no' => $r->refund_no, 'refund_date' => optional($r->refund_date)->toDateString(),
                'amount' => (float) $r->amount, 'mode' => $r->mode, 'reference' => $r->reference, 'notes' => $r->notes,
                'from_advance' => (bool) $r->from_advance,
            ])->values()),
            'confirmed_at'  => optional($this->confirmed_at)->toIso8601String(),
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
                'cancel'  => $this->status === 'draft' && $user?->hasPermission('sales_returns.delete', $companyId),
                'refund'  => $this->status === 'confirmed' && (float) ($this->advance_credit ?? 0) - (float) ($this->advance_refunded ?? 0) > 0.005 && $user?->hasPermission('sales_returns.confirm', $companyId),
            ],
        ];
    }
}
