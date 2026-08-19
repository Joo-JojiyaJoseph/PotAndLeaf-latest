<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionRule extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'user_id', 'location_id', 'rate_type', 'base_percent', 'per_unit_amount',
        'monthly_target', 'target_bonus', 'notes', 'is_active', 'is_supervisor',
        'effective_from', 'effective_to', 'max_commission', 'eligible_bill_kinds',
    ];

    protected function casts(): array
    {
        return [
            'base_percent'        => 'decimal:3',
            'per_unit_amount'     => 'decimal:4',
            'monthly_target'      => 'decimal:2',
            'target_bonus'        => 'decimal:2',
            'max_commission'      => 'decimal:2',
            'is_active'           => 'boolean',
            'is_supervisor'       => 'boolean',
            'effective_from'      => 'date',
            'effective_to'        => 'date',
            'eligible_bill_kinds' => 'array',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tiers()
    {
        return $this->hasMany(CommissionTier::class);
    }

    public function dailyTargetTiers()
    {
        return $this->hasMany(CommissionDailyTargetTier::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
