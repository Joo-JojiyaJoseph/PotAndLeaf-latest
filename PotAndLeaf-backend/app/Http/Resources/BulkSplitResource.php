<?php

namespace App\Http\Resources;

use App\Models\BulkSplit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BulkSplit */
class BulkSplitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $companyId = $this->company_id;

        return [
            'id'                  => $this->id,
            'split_no'            => $this->split_no,
            'split_date'          => optional($this->split_date)->toDateString(),
            'source_product_id'   => $this->source_product_id,
            'source_purchase_id'  => $this->source_purchase_id,
            'source_product_name' => $this->source_product_name,
            'source_qty'          => (float) $this->source_qty,
            'split_total_qty'     => (float) ($this->split_total_qty ?? $this->items?->sum('qty') ?? 0),
            'remaining_qty'       => max(0, (float) $this->source_qty - (float) ($this->split_total_qty ?? $this->items?->sum('qty') ?? 0)),
            'split_mode'          => $this->split_mode,
            'split_param'         => $this->split_param !== null ? (float) $this->split_param : null,
            'source_unit_cost'    => (float) $this->source_unit_cost,
            'total_cost'          => (float) $this->total_cost,
            'status'              => $this->status,
            'confirmed_at'        => $this->confirmed_at?->toIso8601String(),
            'notes'               => $this->notes,
            'items_count'         => $this->when($this->items_count !== null, $this->items_count),
            'items'               => BulkSplitItemResource::collection($this->whenLoaded('items')),
            'can'                 => [
                'confirm' => $this->status === 'draft' && $user?->hasPermission('bulk_splits.confirm', $companyId),
                'cancel'  => $this->status !== 'cancelled' && $user?->hasPermission('bulk_splits.delete', $companyId),
            ],
        ];
    }
}
