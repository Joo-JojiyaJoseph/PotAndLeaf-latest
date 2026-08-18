<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use HasAuditColumns, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'customer_id', 'location_id', 'customer_name', 'sale_no', 'sale_date',
        'is_interstate', 'payment_mode', 'subtotal', 'tax_total', 'round_off',
        'grand_total', 'amount_paid', 'loyalty_points_redeemed', 'loyalty_discount',
        'status', 'notes', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'sale_date'               => 'date',
            'is_interstate'           => 'boolean',
            'subtotal'                => 'decimal:2',
            'tax_total'               => 'decimal:2',
            'round_off'               => 'decimal:2',
            'grand_total'             => 'decimal:2',
            'amount_paid'             => 'decimal:2',
            'loyalty_points_redeemed' => 'integer',
            'loyalty_discount'        => 'decimal:2',
            'confirmed_at'            => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function customerReceipts(): HasMany
    {
        return $this->hasMany(CustomerReceipt::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function paymentStatus(): string
    {
        if ($this->status !== 'confirmed') {
            return 'n/a';
        }
        $due = max(0, (float) $this->grand_total - (float) $this->loyalty_discount);
        $paid = (float) $this->amount_paid;
        if ($paid <= 0) {
            return 'unpaid';
        }
        if ($paid + 0.01 < $due) {
            return 'partial';
        }

        return 'paid';
    }
}
