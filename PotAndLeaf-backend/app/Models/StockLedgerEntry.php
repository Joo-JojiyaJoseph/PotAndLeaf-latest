<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLedgerEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'product_id', 'product_batch_id', 'direction', 'qty', 'unit_cost',
        'balance_after', 'reference_type', 'reference_id', 'note',
        'occurred_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'qty'           => 'decimal:3',
            'unit_cost'     => 'decimal:4',
            'balance_after' => 'decimal:3',
            'occurred_at'   => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
