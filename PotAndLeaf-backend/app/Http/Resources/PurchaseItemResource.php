<?php

namespace App\Http\Resources;

use App\Models\PurchaseItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseItem */
class PurchaseItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'product_id'       => $this->product_id,
            'product_name'     => $this->product_name,
            'hsn_code'         => $this->hsn_code,
            'qty'              => (float) $this->qty,
            'rate'             => (float) $this->rate,
            'discount'         => (float) $this->discount,
            'taxable_value'    => (float) $this->taxable_value,
            'gst_rate'         => (float) $this->gst_rate,
            'cgst_amount'      => (float) $this->cgst_amount,
            'sgst_amount'      => (float) $this->sgst_amount,
            'igst_amount'      => (float) $this->igst_amount,
            'line_total'       => (float) $this->line_total,
            'landed_alloc'     => (float) $this->landed_alloc,
            'landed_unit_cost' => (float) $this->landed_unit_cost,

            'is_bulk'           => (bool) $this->is_bulk,
            'sell_as'           => $this->sell_as,
            'units_per_set'     => $this->units_per_set !== null ? (float) $this->units_per_set : null,
            'split_product_id'  => $this->split_product_id,
            'set_product_id'    => $this->set_product_id,
            'shared_pool_group' => $this->shared_pool_group,
            'split_product'     => $this->whenLoaded('splitProduct', fn () => $this->splitProduct ? [
                'id' => $this->splitProduct->id, 'name' => $this->splitProduct->name, 'sku' => $this->splitProduct->sku,
            ] : null),
            'set_product'       => $this->whenLoaded('setProduct', fn () => $this->setProduct ? [
                'id' => $this->setProduct->id, 'name' => $this->setProduct->name, 'sku' => $this->setProduct->sku,
            ] : null),
        ];
    }
}
