<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockVerificationItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'stock_verification_id', 'product_id', 'product_name',
        'system_qty', 'counted_qty', 'variance', 'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'system_qty'  => 'decimal:3',
            'counted_qty' => 'decimal:3',
            'variance'    => 'decimal:3',
            'unit_cost'   => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
