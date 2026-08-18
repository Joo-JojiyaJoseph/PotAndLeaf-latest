<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'purchase_return_id', 'purchase_item_id', 'product_id', 'product_batch_id', 'product_name',
        'hsn_code', 'qty', 'rate', 'gst_rate', 'taxable_value', 'cgst_amount',
        'sgst_amount', 'igst_amount', 'line_total', 'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'qty'           => 'decimal:3',
            'rate'          => 'decimal:2',
            'gst_rate'      => 'decimal:2',
            'taxable_value' => 'decimal:2',
            'cgst_amount'   => 'decimal:2',
            'sgst_amount'   => 'decimal:2',
            'igst_amount'   => 'decimal:2',
            'line_total'    => 'decimal:2',
            'unit_cost'     => 'decimal:4',
        ];
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
