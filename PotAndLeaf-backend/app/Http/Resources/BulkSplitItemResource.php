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
            'qty'          => (float) $this->qty,
            'weight'       => (float) $this->weight,
            'cost_alloc'       => (float) $this->cost_alloc,
            'unit_cost'        => (float) $this->unit_cost,
            'suggested_retail' => $this->suggested_retail ? (float) $this->suggested_retail : null,
            'retail_price'     => $this->retail_price ? (float) $this->retail_price : null,
            'units'            => BulkSplitUnitResource::collection($this->whenLoaded('units')),
        ];
    }
}
