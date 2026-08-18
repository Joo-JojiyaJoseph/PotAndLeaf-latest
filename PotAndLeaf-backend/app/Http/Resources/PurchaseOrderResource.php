<?php

namespace App\Http\Resources;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseOrder */
class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $companyId = $this->company_id;

        return [
            'id'            => $this->id,
            'po_no'         => $this->po_no,
            'po_date'       => optional($this->po_date)->toDateString(),
            'expected_date' => optional($this->expected_date)->toDateString(),
            'supplier_id'   => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'status'        => $this->status,
            'subtotal'      => (float) $this->subtotal,
            'tax_total'     => (float) $this->tax_total,
            'grand_total'   => (float) $this->grand_total,
            'notes'         => $this->notes,
            'purchase_id'   => $this->purchase_id,
            'items_count'   => $this->when($this->items_count !== null, $this->items_count),
            'items'         => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'product_name' => $i->product_name,
                'qty' => (float) $i->qty, 'rate' => (float) $i->rate, 'gst_rate' => (float) $i->gst_rate,
                'taxable_value' => (float) $i->taxable_value, 'line_total' => (float) $i->line_total,
            ])->values()),
            'can'           => [
                'send'    => $this->status === 'draft' && $user?->hasPermission('po.send', $companyId),
                'convert' => in_array($this->status, ['draft', 'sent'], true) && $user?->hasPermission('po.convert', $companyId),
                'cancel'  => in_array($this->status, ['draft', 'sent'], true) && $user?->hasPermission('po.delete', $companyId),
            ],
        ];
    }
}
