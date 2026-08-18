<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    use HasUuids;

    protected $fillable = ['stock_transfer_id', 'product_id', 'product_batch_id', 'product_name', 'qty', 'received_qty'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'received_qty' => 'decimal:3'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }
}
