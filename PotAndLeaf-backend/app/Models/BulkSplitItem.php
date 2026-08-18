<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkSplitItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'bulk_split_id', 'product_id', 'product_name', 'qty', 'weight',
        'cost_alloc', 'unit_cost', 'suggested_retail', 'retail_price',
    ];

    protected function casts(): array
    {
        return [
            'qty'              => 'decimal:3',
            'weight'           => 'decimal:3',
            'cost_alloc'       => 'decimal:2',
            'unit_cost'        => 'decimal:4',
            'suggested_retail' => 'decimal:2',
            'retail_price'     => 'decimal:2',
        ];
    }

    public function units(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BulkSplitUnit::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
