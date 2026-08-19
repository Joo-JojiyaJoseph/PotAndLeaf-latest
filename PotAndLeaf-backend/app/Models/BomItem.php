<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomItem extends Model
{
    use HasUuids;

    protected $fillable = ['bom_id', 'bom_stage_id', 'component_product_id', 'qty', 'wastage_pct'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'wastage_pct' => 'decimal:3'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(BomStage::class, 'bom_stage_id');
    }
}
