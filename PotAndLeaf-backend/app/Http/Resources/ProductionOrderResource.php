<?php

namespace App\Http\Resources;

use App\Models\ProductionOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductionOrder */
class ProductionOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $companyId = $this->company_id;

        return [
            'id'               => $this->id,
            'order_no'         => $this->order_no,
            'order_date'       => optional($this->order_date)->toDateString(),
            'output_product_id' => $this->output_product_id,
            'output_product'   => $this->outputProduct?->name,
            'bom_id'           => $this->bom_id,
            'bom_name'         => $this->bom?->name,
            'output_quantity'  => (float) $this->output_quantity,
            'commission_pending_qty' => (float) ($this->commission_pending_qty ?? 0),
            'supervisor_id'    => $this->supervisor_id,
            'supervisor'       => $this->whenLoaded('supervisor', fn () => $this->supervisor?->name),
            'total_input_cost' => (float) $this->total_input_cost,
            'output_unit_cost' => (float) $this->output_unit_cost,
            'status'           => $this->status,
            'notes'            => $this->notes,
            'completed_at'     => optional($this->completed_at)->toIso8601String(),
            'barcodes'         => $this->whenLoaded('batches', fn () => $this->batches->map(fn ($b) => [
                'id'      => $b->id,
                'barcode' => $b->barcode,
                'qty'     => (float) $b->qty,
            ])->values()),
            'items'            => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id, 'product_name' => $i->product_name,
                'qty' => (float) $i->qty, 'unit_cost' => (float) $i->unit_cost, 'line_cost' => (float) $i->line_cost,
            ])->values()),
            'can'              => [
                'update'   => $this->status === 'draft' && $user?->hasPermission('production.create', $companyId),
                'complete' => $this->status === 'draft' && $user?->hasPermission('production.complete', $companyId),
                'cancel'   => in_array($this->status, ['draft', 'completed'], true) && $user?->hasPermission('production.delete', $companyId),
            ],
        ];
    }
}
