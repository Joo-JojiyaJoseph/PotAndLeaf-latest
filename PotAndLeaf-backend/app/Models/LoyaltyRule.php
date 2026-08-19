<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRule extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'name', 'rule_type', 'product_id', 'category_id', 'customer_tier',
        'earn_rupees', 'earn_points', 'bonus_points_per_unit', 'min_purchase',
        'max_points_per_transaction', 'effective_from', 'effective_to', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'earn_rupees'                  => 'decimal:2',
            'min_purchase'                 => 'decimal:2',
            'effective_from'               => 'date',
            'effective_to'                 => 'date',
            'is_active'                    => 'boolean',
            'max_points_per_transaction'   => 'integer',
            'earn_points'                  => 'integer',
            'bonus_points_per_unit'        => 'integer',
            'priority'                     => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeActiveOn($query, string $date)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
