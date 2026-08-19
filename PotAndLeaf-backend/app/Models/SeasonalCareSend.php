<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeasonalCareSend extends Model
{
    use HasUuids;

    protected $fillable = ['seasonal_care_rule_id', 'customer_id', 'sale_id', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(SeasonalCareRule::class, 'seasonal_care_rule_id');
    }
}
