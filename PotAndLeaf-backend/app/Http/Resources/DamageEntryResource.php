<?php

namespace App\Http\Resources;

use App\Models\DamageEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DamageEntry */
class DamageEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'entry_no'   => $this->entry_no,
            'entry_date' => optional($this->entry_date)->toDateString(),
            'qty'        => (float) $this->qty,
            'reason'     => $this->reason,
            'notes'      => $this->notes,
            'photo'      => $this->photo,
            'product'    => $this->whenLoaded('product', fn () => [
                'id'   => $this->product?->id,
                'sku'  => $this->product?->sku,
                'name' => $this->product?->name,
            ]),
            'location'   => $this->whenLoaded('location', fn () => $this->location ? [
                'id'   => $this->location->id,
                'name' => $this->location->name,
            ] : null),
            'created_at' => optional($this->created_at)->toDateTimeString(),
        ];
    }
}
