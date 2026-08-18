<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BulkSplit extends Model
{
    use HasAuditColumns, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'source_product_id', 'source_purchase_id', 'source_product_name', 'split_no',
        'split_date', 'source_qty', 'source_unit_cost', 'total_cost', 'status',
        'notes', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'split_date'       => 'date',
            'source_qty'       => 'decimal:3',
            'source_unit_cost' => 'decimal:4',
            'total_cost'       => 'decimal:2',
            'confirmed_at'     => 'datetime',
        ];
    }

    public function sourceProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'source_product_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BulkSplitItem::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(BulkSplitUnit::class);
    }

    public function sourcePurchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'source_purchase_id');
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }
}
