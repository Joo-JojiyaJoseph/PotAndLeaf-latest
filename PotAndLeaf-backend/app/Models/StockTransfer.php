<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTransfer extends Model
{
    use HasAuditColumns, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'to_company_id', 'transfer_type', 'from_location_id', 'to_location_id', 'transfer_no',
        'transfer_date', 'status', 'approved_at', 'rejection_reason',
        'redirected_from_company_id', 'redirected_at', 'redirected_by',
        'notes', 'dispatched_at', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'dispatched_at' => 'datetime',
            'received_at'   => 'datetime',
            'approved_at'   => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function fromCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function toCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'to_company_id');
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where(fn ($q) => $q->where('company_id', $companyId)->orWhere('to_company_id', $companyId));
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isRequested(): bool
    {
        return $this->status === 'requested';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isInTransit(): bool
    {
        return $this->status === 'in_transit';
    }

    public function isIntraCompany(): bool
    {
        return $this->transfer_type === 'intra_company';
    }

    public function destinationCompanyId(): int|string|null
    {
        return $this->isIntraCompany() ? $this->company_id : $this->to_company_id;
    }
}
