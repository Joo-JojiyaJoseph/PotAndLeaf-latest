<?php

namespace App\Http\Resources;

use App\Models\CommissionRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CommissionRule */
class CommissionRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'user_id'        => $this->user_id,
            'user_name'      => $this->user?->name,
            'rate_type'      => $this->rate_type ?? 'percent',
            'base_percent'   => (float) $this->base_percent,
            'per_unit_amount'=> (float) ($this->per_unit_amount ?? 0),
            'monthly_target' => (float) $this->monthly_target,
            'target_bonus'   => (float) $this->target_bonus,
            'notes'          => $this->notes,
            'is_active'      => (bool) $this->is_active,
            'is_supervisor'  => (bool) ($this->is_supervisor ?? false),
            'location_id'    => $this->location_id,
            'effective_from' => optional($this->effective_from)->toDateString(),
            'effective_to'   => optional($this->effective_to)->toDateString(),
            'max_commission' => $this->max_commission !== null ? (float) $this->max_commission : null,
            'eligible_bill_kinds' => $this->eligible_bill_kinds,
            'tiers'          => $this->whenLoaded('tiers', fn () => $this->tiers->map(fn ($t) => [
                'id' => $t->id, 'min_amount' => (float) $t->min_amount, 'max_amount' => $t->max_amount !== null ? (float) $t->max_amount : null,
                'percent' => (float) $t->percent, 'product_id' => $t->product_id, 'category_id' => $t->category_id,
            ])),
            'daily_target_tiers' => $this->whenLoaded('dailyTargetTiers', fn () => $this->dailyTargetTiers->map(fn ($t) => [
                'id' => $t->id, 'min_amount' => (float) $t->min_amount, 'bonus_amount' => (float) $t->bonus_amount,
            ])),
        ];
    }
}
