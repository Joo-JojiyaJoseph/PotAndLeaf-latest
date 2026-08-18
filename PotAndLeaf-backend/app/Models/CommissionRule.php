<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionRule extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'user_id', 'rate_type', 'base_percent', 'per_unit_amount',
        'monthly_target', 'target_bonus', 'notes', 'is_active', 'is_supervisor',
    ];

    protected function casts(): array
    {
        return [
            'base_percent'    => 'decimal:3',
            'per_unit_amount' => 'decimal:4',
            'monthly_target'  => 'decimal:2',
            'target_bonus'    => 'decimal:2',
            'is_active'       => 'boolean',
            'is_supervisor'   => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
