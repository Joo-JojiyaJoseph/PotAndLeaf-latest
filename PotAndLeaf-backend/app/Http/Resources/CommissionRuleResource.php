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
        ];
    }
}
