<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasUuids;

    protected $fillable = ['purchase_order_id', 'product_id', 'product_name', 'qty', 'rate', 'gst_rate', 'taxable_value', 'line_total'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'rate' => 'decimal:2', 'gst_rate' => 'decimal:2', 'taxable_value' => 'decimal:2', 'line_total' => 'decimal:2'];
    }
}
