<?php

namespace App\Http\Resources;

use App\Models\Bom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Bom */
class BomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'product_id'   => $this->product_id,
            'product_name' => $this->product?->name,
            'product_sku'  => $this->product?->sku,
            'name'         => $this->name,
            'output_qty'   => (float) $this->output_qty,
            'is_active'    => (bool) $this->is_active,
            'notes'        => $this->notes,
            'items'        => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'component_product_id' => $i->component_product_id,
                'component_name'       => $i->component?->name,
                'qty'                  => (float) $i->qty,
            ])->values()),
        ];
    }
}
