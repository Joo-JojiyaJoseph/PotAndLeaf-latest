<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionTransaction extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'user_id', 'role', 'branch_id', 'rule_id', 'commission_type', 'source_type', 'source_id',
        'product_id', 'calculation_base', 'rate_percent', 'fixed_bonus', 'amount',
        'rule_snapshot', 'transaction_date', 'period', 'status', 'reversal_of_id',
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

    /**
     * Statuses whose amounts belong in commission totals. The ledger is
     * append-only: a reversed row keeps its positive amount (status
     * "reversed") and its negative reversal row is "accrued", so totals must
     * include both or a cancelled sale would be deducted twice.
     */
    public const COUNTED_STATUSES = ['accrued', 'reversed'];

    /** Rows still open (not yet reversed) — used to find what can be reversed. */
    public function scopeAccrued($query)
    {
        return $query->where('status', 'accrued');
    }

    /** Rows that make up commission totals (originals + their reversals). */
    public function scopeCounted($query)
    {
        return $query->whereIn('status', self::COUNTED_STATUSES);
    }
}
