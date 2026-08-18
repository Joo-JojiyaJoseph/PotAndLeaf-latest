<?php

namespace App\Http\Resources;

use App\Models\SalesReturnItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalesReturnItem */
class SalesReturnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'sale_item_id'  => $this->sale_item_id,
            'product_id'    => $this->product_id,
            'product_name'  => $this->product_name,
            'hsn_code'      => $this->hsn_code,
            'qty'           => (float) $this->qty,
            'rate'          => (float) $this->rate,
            'discount'      => (float) $this->discount,
            'gst_rate'      => (float) $this->gst_rate,
            'taxable_value' => (float) $this->taxable_value,
            'cgst_amount'   => (float) $this->cgst_amount,
            'sgst_amount'   => (float) $this->sgst_amount,
            'igst_amount'   => (float) $this->igst_amount,
            'line_total'    => (float) $this->line_total,
        ];
    }
}
