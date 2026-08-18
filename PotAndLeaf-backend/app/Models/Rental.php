<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rental extends Model
{
    use HasAuditColumns, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'customer_id', 'location_id', 'rental_no', 'start_date',
        'expected_end_date', 'billing_cycle', 'deposit',
        'rental_charge', 'damage_charge', 'missing_charge', 'refund_amount', 'balance_due',
        'return_date', 'settled_at', 'status', 'notes',
        'activated_at', 'returned_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date'        => 'date',
            'expected_end_date' => 'date',
            'return_date'       => 'date',
            'deposit'           => 'decimal:2',
            'rental_charge'     => 'decimal:2',
            'damage_charge'     => 'decimal:2',
            'missing_charge'    => 'decimal:2',
            'refund_amount'     => 'decimal:2',
            'balance_due'       => 'decimal:2',
            'settled_at'        => 'datetime',
            'activated_at'      => 'datetime',
            'returned_at'       => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RentalItem::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(RentalInvoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
