<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasAuditColumns, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'parent_product_id', 'bulk_split_id', 'split_sequence',
        'sku', 'name', 'barcode', 'hsn_code', 'description',
        'category_id', 'brand_id', 'unit_id',
        'gst_rate', 'mrp', 'cost_price', 'dealer_price', 'wholesale_price', 'retail_price',
        'reorder_level', 'opening_stock', 'current_stock',
        'length_cm', 'width_cm', 'height_cm',
        'images', 'status', 'is_rental', 'rental_daily_rate',
        'pool_group_id', 'pool_role', 'units_per_set',
    ];

    protected function casts(): array
    {
        return [
            'images'            => 'array',
            'gst_rate'          => 'decimal:2',
            'mrp'               => 'decimal:2',
            'cost_price'        => 'decimal:2',
            'dealer_price'      => 'decimal:2',
            'wholesale_price'   => 'decimal:2',
            'retail_price'      => 'decimal:2',
            'reorder_level'     => 'decimal:2',
            'opening_stock'     => 'decimal:2',
            'current_stock'     => 'decimal:2',
            'length_cm'         => 'decimal:2',
            'width_cm'          => 'decimal:2',
            'height_cm'         => 'decimal:2',
            'is_rental'         => 'boolean',
            'rental_daily_rate' => 'decimal:2',
            'units_per_set'     => 'decimal:3',
        ];
    }

    // Relationships ------------------------------------------------------

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    public function bulkSplit(): BelongsTo
    {
        return $this->belongsTo(BulkSplit::class, 'bulk_split_id');
    }

    public function splitChildren(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_product_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(ProductBrand::class, 'brand_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'unit_id');
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'product_supplier')
            ->withPivot(['supplier_price', 'is_primary'])
            ->withTimestamps();
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class)->latest('received_at');
    }

    // Scopes -------------------------------------------------------------

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeSearch($query, ?string $term)
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%")
                ->orWhere('hsn_code', 'like', "%{$term}%");
        });
    }

    // Helpers ------------------------------------------------------------

    public function getIsLowStockAttribute(): bool
    {
        // "Needs reorder" only applies when a reorder level is actually set.
        return (float) $this->reorder_level > 0
            && (float) $this->current_stock <= (float) $this->reorder_level;
    }

    /** Part of a shared set/unit stock pool (see PoolStockService). */
    public function getIsPooledAttribute(): bool
    {
        return filled($this->pool_group_id) && filled($this->pool_role);
    }
}
