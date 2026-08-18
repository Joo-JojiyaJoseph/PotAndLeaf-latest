<?php

namespace App\Http\Resources;

use App\Models\AdvanceOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AdvanceOrder */
class AdvanceOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $companyId = $this->company_id;

        return [
            'id'             => $this->id,
            'order_no'       => $this->order_no,
            'order_date'     => optional($this->order_date)->toDateString(),
            'expected_date'  => optional($this->expected_date)->toDateString(),
            'customer_id'    => $this->customer_id,
            'customer_name'  => $this->customer?->name,
            'status'         => $this->status,
            'advance_amount' => (float) $this->advance_amount,
            'subtotal'       => (float) $this->subtotal,
            'tax_total'      => (float) $this->tax_total,
            'grand_total'    => (float) $this->grand_total,
            'balance'        => round((float) $this->grand_total - (float) $this->advance_amount, 2),
            'notes'          => $this->notes,
            'sale_id'        => $this->sale_id,
            'items_count'    => $this->when($this->items_count !== null, $this->items_count),
            'items'          => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'product_name' => $i->product_name,
                'qty' => (float) $i->qty, 'rate' => (float) $i->rate, 'gst_rate' => (float) $i->gst_rate,
                'taxable_value' => (float) $i->taxable_value, 'line_total' => (float) $i->line_total,
            ])->values()),
            'can'            => [
                'fulfill' => $this->status === 'booked' && $user?->hasPermission('advance.fulfill', $companyId),
                'cancel'  => $this->status === 'booked' && $user?->hasPermission('advance.delete', $companyId),
            ],
        ];
    }
}
