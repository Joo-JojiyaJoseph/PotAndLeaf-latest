<?php

namespace App\Http\Resources;

use App\Models\ProductBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductBatch */
class ProductBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'batch_no'      => $this->batch_no,
            'barcode'       => $this->barcode,
            'qty'           => (float) $this->qty,
            'remaining_qty' => (float) $this->remaining_qty,
            'cost_price'    => (float) $this->cost_price,
            'status'        => $this->status,
            'received_at'   => optional($this->received_at)->toDateTimeString(),
            'purchase_id'   => $this->purchase_id,
            'purchase_no'   => $this->whenLoaded('purchase', fn () => $this->purchase?->purchase_no),
            'supplier'      => $this->whenLoaded('supplier', fn () => $this->supplier?->name),
            'product'       => $this->whenLoaded('product', fn () => [
                'id'    => $this->product?->id,
                'sku'   => $this->product?->sku,
                'name'  => $this->product?->name,
                'mrp'   => (float) $this->product?->mrp,
                'price' => (float) $this->product?->retail_price,
            ]),
        ];
    }
}
