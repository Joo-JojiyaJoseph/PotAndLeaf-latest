<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionOrderStage extends Model
{
    use HasUuids;

    protected $fillable = [
        'production_order_id', 'bom_stage_id', 'sort_order', 'name', 'status',
        'supervisor_id', 'material_cost', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'sort_order'    => 'integer',
            'material_cost' => 'decimal:2',
            'started_at'    => 'datetime',
            'completed_at'  => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function bomStage(): BelongsTo
    {
        return $this->belongsTo(BomStage::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductionOrderItem::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
