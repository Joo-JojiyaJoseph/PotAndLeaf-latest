<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use HasAuditColumns, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'supplier_id', 'location_id', 'purchase_no', 'invoice_no', 'invoice_date',
        'purchase_date', 'is_interstate', 'subtotal', 'discount_total', 'tax_total',
        'landed_cost_total', 'grand_total', 'amount_paid', 'status', 'notes', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date'      => 'date',
            'purchase_date'     => 'date',
            'is_interstate'     => 'boolean',
            'subtotal'          => 'decimal:2',
            'discount_total'    => 'decimal:2',
            'tax_total'         => 'decimal:2',
            'landed_cost_total' => 'decimal:2',
            'grand_total'       => 'decimal:2',
            'confirmed_at'      => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
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

    public function paymentStatus(): string
    {
        if ($this->status !== 'confirmed') {
            return 'n/a';
        }
        $paid = (float) $this->amount_paid;
        $total = (float) $this->grand_total;
        if ($paid <= 0) return 'unpaid';
        if ($paid + 0.01 < $total) return 'partial';
        return 'paid';
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
