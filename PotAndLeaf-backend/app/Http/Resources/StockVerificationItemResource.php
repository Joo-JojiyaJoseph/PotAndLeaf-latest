<?php

namespace App\Http\Resources;

use App\Models\StockVerificationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockVerificationItem */
class StockVerificationItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'product_id'   => $this->product_id,
            'product_name' => $this->product_name,
            // Set when this line counted one batch (null = the whole product).
            'product_batch_id' => $this->product_batch_id,
            'batch_no'     => $this->batch_no,
            'system_qty'   => (float) $this->system_qty,
            'counted_qty'  => (float) $this->counted_qty,
            'variance'     => (float) $this->variance,
            'unit_cost'    => (float) $this->unit_cost,
            // [{batch_id, batch_no, qty}] — negative qty = taken off that batch.
            'batch_adjustments' => $this->batch_adjustments ?? [],
        ];
    }
}
