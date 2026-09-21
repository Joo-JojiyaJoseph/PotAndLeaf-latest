<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountingTransaction extends Model
{
    use HasAuditColumns, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'location_id', 'voucher_no', 'voucher_date', 'voucher_type',
        'book', 'source_type', 'source_id', 'narration', 'status',
        'cancellation_reason', 'cancelled_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'voucher_date'  => 'date',
            'cancelled_at'  => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AccountingEntry::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isManual(): bool
    {
        return blank($this->source_type) || $this->source_type === 'manual';
    }

    public function debitTotal(): float
    {
        return round((float) $this->entries->sum('debit'), 2);
    }

    public function creditTotal(): float
    {
        return round((float) $this->entries->sum('credit'), 2);
    }
}
