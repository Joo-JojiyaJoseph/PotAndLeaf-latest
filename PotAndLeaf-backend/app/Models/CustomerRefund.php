<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money really paid back to a customer for returned goods (not kept as advance). */
class CustomerRefund extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'customer_id', 'sales_return_id', 'refund_no', 'refund_date',
        'amount', 'mode', 'reference', 'notes', 'from_advance', 'created_by',
    ];

    protected function casts(): array
    {
        return ['refund_date' => 'date', 'amount' => 'decimal:2', 'from_advance' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
