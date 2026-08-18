<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'sku'             => $this->sku,
            'name'            => $this->name,
            'barcode'         => $this->barcode,
            'hsn_code'        => $this->hsn_code,
            'description'     => $this->description,
            'category_id'     => $this->category_id,
            'brand_id'        => $this->brand_id,
            'unit_id'         => $this->unit_id,
            'category'        => $this->whenLoaded('category', fn () => $this->category?->name),
            'brand'           => $this->whenLoaded('brand', fn () => $this->brand?->name),
            'unit'            => $this->whenLoaded('unit', fn () => $this->unit?->short_name ?? $this->unit?->name),
            'gst_rate'        => (float) $this->gst_rate,
            'mrp'             => (float) $this->mrp,
            'cost_price'      => $this->when(
                $this->canViewCost($request),
                (float) $this->cost_price,
            ),
            'dealer_price'    => (float) $this->dealer_price,
            'wholesale_price' => (float) $this->wholesale_price,
            'retail_price'    => (float) $this->retail_price,
            'reorder_level'   => (float) $this->reorder_level,
            'opening_stock'   => (float) $this->opening_stock,
            'current_stock'   => (float) $this->current_stock,
            'length_cm'       => $this->length_cm !== null ? (float) $this->length_cm : null,
            'width_cm'        => $this->width_cm !== null ? (float) $this->width_cm : null,
            'height_cm'       => $this->height_cm !== null ? (float) $this->height_cm : null,
            'is_low_stock'    => $this->is_low_stock,
            'images'          => $this->images ?? [],
            'status'          => $this->status,
            'is_rental'       => (bool) $this->is_rental,
            'rental_daily_rate' => $this->rental_daily_rate !== null ? (float) $this->rental_daily_rate : null,
            'pool_group_id'   => $this->pool_group_id,
            'pool_role'       => $this->pool_role,
            'units_per_set'   => $this->units_per_set !== null ? (float) $this->units_per_set : null,
            'linked_skus'     => $this->is_pooled ? $this->linkedSkus() : [],
            'suppliers'       => $this->whenLoaded('suppliers', fn () => $this->suppliers->map(fn ($s) => [
                'supplier_id'    => $s->id,
                'name'           => $s->name,
                'supplier_price' => (float) $s->pivot->supplier_price,
                'is_primary'     => (bool) $s->pivot->is_primary,
            ])->values()),
            'can' => [
                'update' => $request->user()?->can('update', $this->resource),
                'delete' => $request->user()?->can('delete', $this->resource),
            ],
        ];
    }

    /** Sibling SKUs sharing this product's stock pool (see PoolStockService). */
    private function linkedSkus(): array
    {
        return \App\Models\Product::forCompany($this->company_id)
            ->where('pool_group_id', $this->pool_group_id)
            ->where('id', '!=', $this->id)
            ->get(['id', 'sku', 'name', 'pool_role'])
            ->map(fn ($p) => [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'pool_role' => $p->pool_role,
            ])
            ->values()
            ->all();
    }

    private function canViewCost(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        if ((bool) $user->is_super_admin) {
            return true;
        }
        $companyId = $this->company_id;

        return $user->hasPermission('*', $companyId)
            || $user->hasPermission('products.view_cost', $companyId);
    }
}
