<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeasonalCareRule extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'name', 'product_id', 'category_id', 'days_after_purchase',
        'season_months', 'message_template', 'is_active', 'max_sends_per_customer',
    ];

    protected function casts(): array
    {
        return [
            'season_months' => 'array',
            'is_active'     => 'boolean',
        ];
    }

    public function sends(): HasMany
    {
        return $this->hasMany(SeasonalCareSend::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
