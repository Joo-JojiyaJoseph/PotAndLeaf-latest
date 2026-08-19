<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionTransaction extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'user_id', 'commission_type', 'source_type', 'source_id',
        'product_id', 'calculation_base', 'rate_percent', 'fixed_bonus', 'amount',
        'rule_snapshot', 'transaction_date', 'status', 'reversal_of_id',
    ];

    protected function casts(): array
    {
        return [
            'calculation_base'  => 'decimal:2',
            'rate_percent'      => 'decimal:3',
            'fixed_bonus'       => 'decimal:2',
            'amount'            => 'decimal:2',
            'rule_snapshot'     => 'array',
            'transaction_date'  => 'date',
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

    public function scopeAccrued($query)
    {
        return $query->where('status', 'accrued');
    }
}
