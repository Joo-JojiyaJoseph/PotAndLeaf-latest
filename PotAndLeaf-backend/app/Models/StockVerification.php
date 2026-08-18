<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockVerification extends Model
{
    use HasAuditColumns, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'count_no', 'count_date', 'location_note', 'status',
        'notes', 'rejection_reason', 'submitted_at', 'approved_at', 'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'count_date'   => 'date',
            'submitted_at' => 'datetime',
            'approved_at'  => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockVerificationItem::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }
}
