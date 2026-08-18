<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A received lot of one product, created when a purchase is confirmed. Each
 * batch carries its own barcode (see BarcodeGenerator::forBatch). Phase 1 uses
 * this for labelling and traceability; remaining_qty/status are reserved for
 * batch-level costing later.
 */
class ProductBatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'product_id', 'purchase_id', 'purchase_item_id', 'production_order_id', 'supplier_id',
        'location_id', 'batch_no', 'barcode', 'qty', 'remaining_qty', 'cost_price',
        'status', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'qty'           => 'decimal:3',
            'remaining_qty' => 'decimal:3',
            'cost_price'    => 'decimal:4',
            'received_at'   => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
