<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyLedgerEntry extends Model
{
    use HasUuids;

    protected $table = 'loyalty_ledger';

    protected $fillable = [
        'company_id', 'customer_id', 'type', 'points', 'balance_after',
        'reference_type', 'reference_id', 'note', 'rule_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'points'         => 'integer',
            'balance_after'  => 'integer',
            'rule_snapshot'  => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
