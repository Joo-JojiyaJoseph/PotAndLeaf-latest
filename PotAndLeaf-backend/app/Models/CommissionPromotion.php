<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionPromotion extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'name', 'product_id', 'category_id', 'start_date', 'end_date',
        'min_qty', 'bonus_per_unit', 'bonus_fixed', 'bonus_percent', 'eligible_user_ids', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_date'        => 'date',
            'end_date'          => 'date',
            'min_qty'           => 'decimal:3',
            'bonus_per_unit'    => 'decimal:2',
            'bonus_fixed'       => 'decimal:2',
            'bonus_percent'     => 'decimal:3',
            'eligible_user_ids' => 'array',
            'is_active'         => 'boolean',
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

    public function scopeActiveOn($query, string $date)
    {
        return $query->where('is_active', true)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date);
    }
}
