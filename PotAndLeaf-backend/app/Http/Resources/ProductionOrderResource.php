<?php

namespace App\Http\Resources;

use App\Models\ProductionOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductionOrder */
class ProductionOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $companyId = $this->company_id;
        $isMultiStage = $this->isMultiStage();

        return [
            'id'               => $this->id,
            'company_id'       => $this->company_id,
            'order_no'         => $this->order_no,
            'order_date'       => optional($this->order_date)->toDateString(),
            'output_product_id' => $this->output_product_id,
            'output_product'   => $this->outputProduct?->name,
            'bom_id'           => $this->bom_id,
            'bom_name'         => $this->outputProduct?->name ?? $this->bom?->name,
            'is_multi_stage'   => $isMultiStage,
            'recipe'           => $this->whenLoaded('bom', function () {
                $bom = $this->bom;
                if (! $bom) {
                    return null;
                }
                $mapItem = fn ($i) => [
                    'component_product_id' => $i->component_product_id,
                    'component_name'       => $i->component?->name,
                    'qty'                  => (float) $i->qty,
                    'current_stock'        => $i->component ? (float) $i->component->current_stock : null,
                    'cost_price'           => $i->component ? (float) $i->component->cost_price : null,
                ];

                return [
                    'items'  => $bom->relationLoaded('items')
                        ? $bom->items->whereNull('bom_stage_id')->values()->map($mapItem)
                        : [],
                    'stages' => $bom->relationLoaded('stages')
                        ? $bom->stages->map(fn ($s) => [
                            'name'  => $s->name,
                            'items' => $s->relationLoaded('items') ? $s->items->map($mapItem)->values() : [],
                        ])->values()
                        : [],
                ];
            }),
            'output_quantity'  => (float) $this->output_quantity,
            'commission_pending_qty' => (float) ($this->commission_pending_qty ?? 0),
            'supervisor_id'    => $this->supervisor_id,
            'supervisor'       => $this->whenLoaded('supervisor', fn () => $this->supervisor?->name),
            'location_id'      => $this->location_id,
            'location'         => $this->whenLoaded('location', fn () => $this->location?->name),
            'total_input_cost' => (float) $this->total_input_cost,
            'output_unit_cost' => (float) $this->output_unit_cost,
            // Costs beyond the materials, and the margin used to suggest a price.
            'setting_charge'   => (float) $this->setting_charge,
            'misc_charge'      => (float) $this->misc_charge,
            'margin_type'      => $this->margin_type ?? 'percent',
            'margin_value'     => (float) $this->margin_value,
            'material_cost'    => $this->status === 'completed'
                ? round(max(0, (float) $this->total_input_cost - (float) $this->setting_charge - (float) $this->misc_charge), 2)
                : null,
            'suggested_price'  => $this->status === 'completed' && (float) $this->output_unit_cost > 0
                ? round((float) $this->output_unit_cost + (($this->margin_type ?? 'percent') === 'amount'
                    ? (float) $this->margin_value
                    : (float) $this->output_unit_cost * (float) $this->margin_value / 100), 2)
                : null,
            // The product's selling prices (as on the product form), for the detail page.
            'output_prices'    => $this->when($this->relationLoaded('items'), fn () => ($p = \App\Models\Product::query()->whereKey($this->output_product_id)->first(['mrp', 'retail_price', 'wholesale_price', 'dealer_price', 'gst_rate']))
                ? ['mrp' => (float) $p->mrp, 'retail_price' => (float) $p->retail_price, 'wholesale_price' => (float) $p->wholesale_price, 'dealer_price' => (float) $p->dealer_price, 'gst_rate' => (float) $p->gst_rate]
                : null),
            'status'           => $this->status,
            'notes'            => $this->notes,
            'completed_at'     => optional($this->completed_at)->toIso8601String(),
            'barcodes'         => $this->whenLoaded('batches', fn () => $this->batches->map(fn ($b) => [
                'id'            => $b->id,
                'barcode'       => $b->barcode,
                'qty'           => (float) $b->qty,
                'supervisor_id' => $b->supervisor_id,
            ])->values()),
            'items'            => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'production_order_stage_id' => $i->production_order_stage_id,
                'product_name' => $i->product_name,
                'qty' => (float) $i->qty,
                'unit_cost' => (float) $i->unit_cost,
                'line_cost' => (float) $i->line_cost,
            ])->values()),
            'stages'           => $this->whenLoaded('stages', function () use ($user, $companyId) {
                return $this->stages->map(function ($stage) use ($user, $companyId) {
                    $previousCompleted = $this->stages
                        ->where('sort_order', '<', $stage->sort_order)
                        ->every(fn ($s) => $s->isCompleted());

                    return [
                        'id'            => $stage->id,
                        'bom_stage_id'  => $stage->bom_stage_id,
                        'sort_order'    => (int) $stage->sort_order,
                        'name'          => $stage->name,
                        'status'        => $stage->status,
                        'material_cost' => (float) $stage->material_cost,
                        'supervisor'    => $stage->relationLoaded('supervisor') ? $stage->supervisor?->name : null,
                        'supervisor_id' => $stage->supervisor_id,
                        'started_at'    => optional($stage->started_at)->toIso8601String(),
                        'completed_at'  => optional($stage->completed_at)->toIso8601String(),
                        'can'           => [
                            'start'    => $stage->isPending()
                                && $previousCompleted
                                && in_array($this->status, ['draft', 'in_progress'], true)
                                && $user?->hasPermission('production.complete', $companyId),
                            'complete' => $stage->isInProgress()
                                && $user?->hasPermission('production.complete', $companyId),
                        ],
                    ];
                })->values();
            }),
            'can'              => [
                'set_prices'    => $this->status !== 'cancelled' && $user?->hasPermission('production.complete', $companyId),
                'update'        => $this->status === 'draft' && $user?->hasPermission('production.create', $companyId),
                'complete'      => $this->status === 'draft' && ! $isMultiStage && $user?->hasPermission('production.complete', $companyId),
                'cancel'        => in_array($this->status, ['draft', 'in_progress', 'completed'], true) && $user?->hasPermission('production.delete', $companyId),
                'change_status' => (bool) $user?->is_super_admin,
            ],
        ];
    }
}
