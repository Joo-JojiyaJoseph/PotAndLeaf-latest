<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackorderItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'backorder_id', 'product_id', 'sale_item_id', 'product_name',
        'ordered_qty', 'fulfilled_qty', 'cancelled_qty', 'rate',
    ];

    protected function casts(): array
    {
        return [
            'ordered_qty'   => 'decimal:3',
            'fulfilled_qty' => 'decimal:3',
            'cancelled_qty' => 'decimal:3',
            'rate'          => 'decimal:2',
        ];
    }

    public function pendingQty(): float
    {
        return max(0, (float) $this->ordered_qty - (float) $this->fulfilled_qty - (float) $this->cancelled_qty);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function backorder(): BelongsTo
    {
        return $this->belongsTo(Backorder::class);
    }
}
