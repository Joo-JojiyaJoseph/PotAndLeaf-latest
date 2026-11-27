<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money really received back from a supplier for goods we returned (not kept as advance). */
class SupplierRefund extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'supplier_id', 'purchase_return_id', 'refund_no', 'refund_date',
        'amount', 'mode', 'reference', 'notes', 'from_advance', 'created_by',
    ];

    protected function casts(): array
    {
        return ['refund_date' => 'date', 'amount' => 'decimal:2', 'from_advance' => 'boolean'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
