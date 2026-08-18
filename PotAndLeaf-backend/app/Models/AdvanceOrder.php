<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdvanceOrder extends Model
{
    use HasAuditColumns, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'customer_id', 'sale_id', 'order_no', 'order_date',
        'expected_date', 'status', 'advance_amount', 'subtotal', 'tax_total', 'grand_total', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'order_date'     => 'date',
            'expected_date'  => 'date',
            'advance_amount' => 'decimal:2',
            'subtotal'       => 'decimal:2',
            'tax_total'      => 'decimal:2',
            'grand_total'    => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(AdvanceOrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function isBooked(): bool
    {
        return $this->status === 'booked';
    }
}
