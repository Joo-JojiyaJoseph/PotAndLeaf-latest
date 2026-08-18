<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductionOrderItem extends Model
{
    use HasUuids;

    protected $fillable = ['production_order_id', 'component_product_id', 'product_name', 'qty', 'unit_cost', 'line_cost'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_cost' => 'decimal:4', 'line_cost' => 'decimal:2'];
    }
}
