<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomItem extends Model
{
    use HasUuids;

    protected $fillable = ['bom_id', 'component_product_id', 'qty'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
