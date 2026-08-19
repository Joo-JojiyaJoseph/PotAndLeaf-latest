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
            'bom_name'         => $this->bom?->name,
            'is_multi_stage'   => $isMultiStage,
            'output_quantity'  => (float) $this->output_quantity,
            'commission_pending_qty' => (float) ($this->commission_pending_qty ?? 0),
            'supervisor_id'    => $this->supervisor_id,
            'supervisor'       => $this->whenLoaded('supervisor', fn () => $this->supervisor?->name),
            'location_id'      => $this->location_id,
            'location'         => $this->whenLoaded('location', fn () => $this->location?->name),
            'total_input_cost' => (float) $this->total_input_cost,
            'output_unit_cost' => (float) $this->output_unit_cost,
            'status'           => $this->status,
            'notes'            => $this->notes,
            'completed_at'     => optional($this->completed_at)->toIso8601String(),
            'barcodes'         => $this->whenLoaded('batches', fn () => $this->batches->map(fn ($b) => [
                'id'      => $b->id,
                'barcode' => $b->barcode,
                'qty'     => (float) $b->qty,
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
                'update'   => $this->status === 'draft' && $user?->hasPermission('production.create', $companyId),
                'complete' => $this->status === 'draft' && ! $isMultiStage && $user?->hasPermission('production.complete', $companyId),
                'cancel'   => in_array($this->status, ['draft', 'in_progress', 'completed'], true) && $user?->hasPermission('production.delete', $companyId),
            ],
        ];
    }
}
