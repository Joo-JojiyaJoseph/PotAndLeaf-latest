<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionOrder extends Model
{
    use HasAuditColumns, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'bom_id', 'output_product_id', 'location_id', 'supervisor_id', 'order_no',
        'order_date', 'output_quantity', 'commission_pending_qty', 'total_input_cost', 'output_unit_cost',
        'status', 'notes', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'order_date'              => 'date',
            'output_quantity'         => 'decimal:3',
            'commission_pending_qty'  => 'decimal:3',
            'total_input_cost'        => 'decimal:2',
            'output_unit_cost'        => 'decimal:4',
            'completed_at'            => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductionOrderItem::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function outputProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'output_product_id');
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
