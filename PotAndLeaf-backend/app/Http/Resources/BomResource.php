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
            'id'             => $this->id,
            'company_id'     => $this->company_id,
            'product_id'     => $this->product_id,
            'product_name'   => $this->product?->name,
            'product_sku'    => $this->product?->sku,
            'name'           => $this->name,
            'output_qty'     => (float) $this->output_qty,
            'is_active'      => (bool) $this->is_active,
            'is_multi_stage' => $this->isMultiStage(),
            'notes'          => $this->notes,
            'items'          => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id'                   => $i->id,
                'bom_stage_id'         => $i->bom_stage_id,
                'component_product_id' => $i->component_product_id,
                'component_name'       => $i->component?->name,
                'qty'                  => (float) $i->qty,
                'wastage_pct'          => (float) ($i->wastage_pct ?? 0),
            ])->values()),
            'stages'         => $this->whenLoaded('stages', fn () => $this->stages->map(fn ($s) => [
                'id'         => $s->id,
                'sort_order' => (int) $s->sort_order,
                'name'       => $s->name,
                'notes'      => $s->notes,
                'items'      => $s->relationLoaded('items') ? $s->items->map(fn ($i) => [
                    'id'                   => $i->id,
                    'component_product_id' => $i->component_product_id,
                    'component_name'       => $i->component?->name,
                    'qty'                  => (float) $i->qty,
                    'wastage_pct'          => (float) ($i->wastage_pct ?? 0),
                ])->values() : [],
            ])->values()),
        ];
    }
}
