<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockVerification extends Model
{
    use HasAuditColumns, HasFactory, HasUuids;

    protected $fillable = [
        'company_id', 'count_no', 'count_date', 'location_note', 'status',
        'notes', 'rejection_reason', 'submitted_at', 'approved_at', 'approved_by',
        'location_id', 'applied_at', 'applied_by', 'verified_at', 'verified_by', 'is_quick',
    ];

    protected function casts(): array
    {
        return [
            'count_date'   => 'date',
            'submitted_at' => 'datetime',
            'approved_at'  => 'datetime',
            'applied_at'   => 'datetime',
            'verified_at'  => 'datetime',
            'is_quick'     => 'boolean',
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

    public function location(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Stock already changed; waiting for head office / admin to check it. */
    public function isApplied(): bool
    {
        return $this->status === 'applied';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }
}
