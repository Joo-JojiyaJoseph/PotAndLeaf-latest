<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesReturn extends Model
{
    use HasAuditColumns, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'sale_id', 'customer_id', 'location_id', 'return_no', 'return_date',
        'is_interstate', 'reason', 'subtotal', 'tax_total', 'round_off', 'grand_total',
        'status', 'notes', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'return_date'   => 'date',
            'is_interstate' => 'boolean',
            'subtotal'      => 'decimal:2',
            'tax_total'     => 'decimal:2',
            'round_off'     => 'decimal:2',
            'grand_total'   => 'decimal:2',
            'confirmed_at'  => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }
}
