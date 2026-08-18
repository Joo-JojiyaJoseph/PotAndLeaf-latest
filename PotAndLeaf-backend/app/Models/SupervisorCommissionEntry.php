<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupervisorCommissionEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'user_id', 'product_id', 'production_order_id',
        'trigger_event', 'reference_type', 'reference_id',
        'qty', 'unit_value', 'amount', 'accrued_date',
    ];

    protected function casts(): array
    {
        return [
            'qty'          => 'decimal:3',
            'unit_value'   => 'decimal:4',
            'amount'       => 'decimal:2',
            'accrued_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
