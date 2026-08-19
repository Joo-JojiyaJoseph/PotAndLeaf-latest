<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionDailyTargetTier extends Model
{
    use HasUuids;

    protected $fillable = ['commission_rule_id', 'min_amount', 'bonus_amount', 'sort_order'];

    protected function casts(): array
    {
        return [
            'min_amount'   => 'decimal:2',
            'bonus_amount' => 'decimal:2',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }
}
