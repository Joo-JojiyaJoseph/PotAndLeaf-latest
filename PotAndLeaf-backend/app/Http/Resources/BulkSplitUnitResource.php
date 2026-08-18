<?php

namespace App\Http\Resources;

use App\Models\BulkSplitUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BulkSplitUnit */
class BulkSplitUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'barcode'  => $this->barcode,
            'unit_no'  => (int) $this->unit_no,
            'product_id' => $this->product_id,
        ];
    }
}
