<?php

namespace App\Http\Resources;

use App\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockTransfer */
class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $currentCompanyId = $request->attributes->get('company')?->id;
        $isSource = (string) $this->company_id === (string) $currentCompanyId;
        $isDest = (string) $this->to_company_id === (string) $currentCompanyId;

        return [
            'id'            => $this->id,
            'transfer_no'   => $this->transfer_no,
            'transfer_date' => optional($this->transfer_date)->toDateString(),
            'from_company_id' => $this->company_id,
            'to_company_id'   => $this->to_company_id,
            'from_company'    => $this->fromCompany?->name ?? $this->fromLocation?->name,
            'to_company'      => $this->toCompany?->name ?? $this->toLocation?->name,
            // legacy aliases for older clients
            'from_location' => $this->fromCompany?->name ?? $this->fromLocation?->name,
            'to_location'   => $this->toCompany?->name ?? $this->toLocation?->name,
            'status'        => $this->status,
            'approved_at'   => optional($this->approved_at)->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'is_incoming'   => $isDest && ! $isSource,
            'notes'         => $this->notes,
            'dispatched_at' => optional($this->dispatched_at)->toIso8601String(),
            'received_at'   => optional($this->received_at)->toIso8601String(),
            'items_count'   => $this->when($this->items_count !== null, $this->items_count),
            'items'         => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'product_name' => $i->product_name,
                'qty' => (float) $i->qty, 'received_qty' => (float) $i->received_qty,
                'batch_no' => $i->batch?->batch_no,
                'barcode' => $i->batch?->barcode,
                'source_purchase' => $i->batch?->purchase?->purchase_no,
            ])->values()),
            'can'           => [
                'approve'  => $this->status === 'requested' && ($isSource || $isDest) && $user?->hasPermission('transfers.approve', $currentCompanyId),
                'reject'   => $this->status === 'requested' && ($isSource || $isDest) && $user?->hasPermission('transfers.approve', $currentCompanyId),
                'dispatch' => $this->status === 'draft' && $isSource && $user?->hasPermission('transfers.dispatch', $this->company_id),
                'redirect' => $this->status === 'in_transit' && $isSource && $user?->hasPermission('transfers.approve', $currentCompanyId),
                'receive'  => $this->status === 'in_transit' && $isDest && $user?->hasPermission('transfers.receive', $currentCompanyId),
                'cancel'   => in_array($this->status, ['draft', 'in_transit', 'requested'], true) && $isSource && $user?->hasPermission('transfers.delete', $this->company_id),
            ],
        ];
    }
}
