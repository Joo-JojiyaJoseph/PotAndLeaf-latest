<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkSplitUnit extends Model
{
    use HasUuids;

    protected $fillable = [
        'bulk_split_id', 'bulk_split_item_id', 'product_id', 'barcode', 'unit_no',
    ];

    public function split(): BelongsTo
    {
        return $this->belongsTo(BulkSplit::class, 'bulk_split_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BulkSplitItem::class, 'bulk_split_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
