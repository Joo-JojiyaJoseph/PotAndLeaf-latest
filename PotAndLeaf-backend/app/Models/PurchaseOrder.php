<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use HasAuditColumns, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'supplier_id', 'purchase_id', 'po_no', 'po_date',
        'expected_date', 'status', 'subtotal', 'tax_total', 'grand_total', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'po_date'       => 'date',
            'expected_date' => 'date',
            'subtotal'      => 'decimal:2',
            'tax_total'     => 'decimal:2',
            'grand_total'   => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'sent'], true);
    }
}
