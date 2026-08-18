<?php

namespace App\Http\Resources;

use App\Models\BulkSplitItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BulkSplitItem */
class BulkSplitItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'product_id'   => $this->product_id,
            'product_name' => $this->product_name,
            'split_label'  => $this->split_label,
            'split_sequence' => $this->split_sequence,
            'sku'          => $this->whenLoaded('product', fn () => $this->product?->sku),
            'barcode'      => $this->whenLoaded('product', fn () => $this->product?->barcode),
            'qty'          => (float) $this->qty,
            'weight'       => (float) $this->weight,
            'cost_alloc'       => (float) $this->cost_alloc,
            'unit_cost'        => (float) $this->unit_cost,
            'suggested_retail' => $this->suggested_retail ? (float) $this->suggested_retail : null,
            'retail_price'     => $this->retail_price ? (float) $this->retail_price : null,
            'units'            => BulkSplitUnitResource::collection($this->whenLoaded('units')),
            'batch_no'         => $this->when(
                $this->relationLoaded('splitBatch') && $this->splitBatch,
                fn () => $this->splitBatch->batch_no,
            ),
            'batch_id'         => $this->when(
                $this->relationLoaded('splitBatch') && $this->splitBatch,
                fn () => $this->splitBatch->id,
            ),
            'batch_barcode'    => $this->when(
                $this->relationLoaded('splitBatch') && $this->splitBatch,
                fn () => $this->splitBatch->barcode,
            ),
            'batch_status'     => $this->when(
                $this->relationLoaded('splitBatch') && $this->splitBatch,
                fn () => $this->splitBatch->status,
            ),
            'unit_name'        => $this->when(
                $this->relationLoaded('product') && $this->product?->relationLoaded('unit'),
                fn () => $this->product?->unit?->short_name ?? $this->product?->unit?->name,
            ),
        ];
    }
}
