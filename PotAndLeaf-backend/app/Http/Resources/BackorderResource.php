<?php

namespace App\Http\Resources;

use App\Models\Backorder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Backorder */
class BackorderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $companyId = $this->company_id;

        return [
            'id'            => $this->id,
            'company_id'    => $this->company_id,
            'order_no'      => $this->order_no,
            'order_date'    => optional($this->order_date)->toDateString(),
            'expected_date' => optional($this->expected_date)->toDateString(),
            'customer_id'   => $this->customer_id,
            'customer_name' => $this->customer?->name,
            'sale_id'       => $this->sale_id,
            'status'        => $this->status,
            'notes'         => $this->notes,
            'items_count'   => $this->when($this->items_count !== null, $this->items_count),
            'items'         => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id'            => $i->id,
                'product_id'    => $i->product_id,
                'product_name'  => $i->product_name,
                'ordered_qty'   => (float) $i->ordered_qty,
                'fulfilled_qty' => (float) $i->fulfilled_qty,
                'cancelled_qty' => (float) $i->cancelled_qty,
                'pending_qty'   => $i->pendingQty(),
                'rate'          => (float) $i->rate,
                'current_stock' => $i->relationLoaded('product') ? (float) ($i->product?->current_stock ?? 0) : null,
            ])->values()),
            'can'           => [
                'fulfill' => $this->isOpen() && $user?->hasPermission('backorder.fulfill', $companyId),
                'cancel'  => $this->isOpen() && $user?->hasPermission('backorder.delete', $companyId),
            ],
        ];
    }
}
