<?php

namespace App\Http\Resources;

use App\Models\StockLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockLedgerEntry */
class StockLedgerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'direction'      => $this->direction,
            'qty'            => (float) $this->qty,
            'unit_cost'      => $this->unit_cost !== null ? (float) $this->unit_cost : null,
            'balance_after'  => (float) $this->balance_after,
            'reference_type' => $this->reference_type,
            'reference_id'   => $this->reference_id,
            'reference_label'=> $this->referenceLabel($this->reference_type),
            'note'           => $this->note,
            'occurred_at'    => optional($this->occurred_at)->toDateTimeString(),
            'product'        => $this->whenLoaded('product', fn () => [
                'id'   => $this->product?->id,
                'sku'  => $this->product?->sku,
                'name' => $this->product?->name,
            ]),
        ];
    }

    private function referenceLabel(?string $type): string
    {
        return match ($type) {
            'purchase'               => 'Purchase',
            'purchase-cancel'        => 'Purchase reversal',
            'sale'                   => 'Sale',
            'sale-cancel'            => 'Sale reversal',
            'production'             => 'Production',
            'production-cancel'      => 'Production reversal',
            'bulk-split'             => 'Bulk split',
            'bulk-split-cancel'      => 'Bulk split reversal',
            'transfer'               => 'Transfer',
            'purchase-return'        => 'Purchase return',
            'purchase-return-cancel' => 'Purchase return reversal',
            'sales-return'           => 'Sales return',
            'sales-return-cancel'    => 'Sales return reversal',
            'stock-verification'     => 'Stock verification',
            'rental'                 => 'Rental',
            'rental-return'          => 'Rental return',
            'rental-cancel'          => 'Rental reversal',
            default                  => $type ? ucfirst(str_replace('-', ' ', $type)) : '—',
        };
    }
}
