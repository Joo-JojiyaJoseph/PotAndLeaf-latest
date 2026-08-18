<?php

namespace App\Http\Resources;

use App\Models\StockVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockVerification */
class StockVerificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $companyId = $this->company_id;

        return [
            'id'               => $this->id,
            'count_no'         => $this->count_no,
            'count_date'       => optional($this->count_date)->toDateString(),
            'location_note'    => $this->location_note,
            'status'           => $this->status,
            'notes'            => $this->notes,
            'rejection_reason' => $this->rejection_reason,
            'submitted_at'     => optional($this->submitted_at)->toDateTimeString(),
            'approved_at'      => optional($this->approved_at)->toDateTimeString(),
            'items_count'      => $this->when($this->items_count !== null, $this->items_count),
            'items'            => StockVerificationItemResource::collection($this->whenLoaded('items')),
            'can'              => [
                'submit'  => $this->status === 'draft' && $user?->hasPermission('stock_verifications.create', $companyId),
                'approve' => $this->status === 'submitted' && $user?->hasPermission('stock_verifications.approve', $companyId),
                'reject'  => $this->status === 'submitted' && $user?->hasPermission('stock_verifications.approve', $companyId),
            ],
        ];
    }
}
