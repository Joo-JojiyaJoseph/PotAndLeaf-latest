<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BomStage extends Model
{
    use HasUuids;

    protected $fillable = ['bom_id', 'sort_order', 'name', 'notes'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BomItem::class);
    }
}
