<?php

namespace App\Http\Resources;

use App\Models\Sale;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Sale */
class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $companyId = $this->company_id;
        $settings = app(SettingsService::class);
        $cancelRequiresApproval = $settings->get($companyId, 'sale_cancel_requires_approval') === '1';

        return [
            'id'            => $this->id,
            'company_id'    => $this->company_id,
            'sale_no'       => $this->sale_no,
            'sale_date'     => optional($this->sale_date)->toDateString(),
            'customer_id'   => $this->customer_id,
            'customer_name' => $this->customer_name,
            'company'       => $this->whenLoaded('company', fn () => [
                'name' => $this->company->name, 'legal_name' => $this->company->legal_name,
                'gst_number' => $this->company->gst_number, 'address' => $this->company->address,
                'phone' => $this->company->phone, 'email' => $this->company->email,
                'state' => $this->company->state, 'state_code' => $this->company->state_code,
            ]),
            'customer'      => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id, 'name' => $this->customer->name, 'type' => $this->customer->type?->value,
            ] : null),
            'is_interstate' => (bool) $this->is_interstate,
            'payment_mode'  => $this->payment_mode,
            'bill_kind'     => $this->bill_kind ?? 'tax_invoice',
            'subtotal'      => (float) $this->subtotal,
            'tax_total'     => (float) $this->tax_total,
            'round_off'     => (float) $this->round_off,
            'grand_total'             => (float) $this->grand_total,
            'amount_paid'             => (float) $this->amount_paid,
            'balance'                 => round(max(0, (float) $this->grand_total - (float) $this->loyalty_discount - (float) $this->amount_paid), 2),
            'loyalty_points_redeemed' => (int) $this->loyalty_points_redeemed,
            'loyalty_discount'        => (float) $this->loyalty_discount,
            'payment_status'          => $this->paymentStatus(),
            'bill_type'               => $this->whenLoaded('customer', fn () => $this->customer?->type?->value),
            'status'                  => $this->status,
            'notes'         => $this->notes,
            'confirmed_at'  => optional($this->confirmed_at)->toIso8601String(),
            'cancel_requested_at'     => optional($this->cancel_requested_at)->toIso8601String(),
            'cancel_reason'           => $this->cancel_reason,
            'cancel_rejection_reason' => $this->cancel_rejection_reason,
            'cancel_requested_by'     => $this->whenLoaded('cancelRequestedBy', fn () => $this->cancelRequestedBy?->name),
            'cancel_reviewed_by'      => $this->whenLoaded('cancelReviewedBy', fn () => $this->cancelReviewedBy?->name),
            'entered_by'    => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_by'    => $this->created_by,
            'items_count'   => $this->when($this->items_count !== null, $this->items_count),
            'items'         => SaleItemResource::collection($this->whenLoaded('items')),
            'can'           => [
                'confirm'         => $this->status === 'draft' && $user?->hasPermission('sales.confirm', $companyId),
                'cancel'          => $this->status !== 'cancelled'
                    && ! $this->hasCancelRequest()
                    && (
                        (in_array($this->status, ['draft', 'proforma'], true) && $user?->hasPermission('sales.delete', $companyId))
                        || ($this->isConfirmed() && ! $cancelRequiresApproval && $user?->hasPermission('sales.delete', $companyId))
                    ),
                'cancel_request'  => $this->isConfirmed()
                    && ! $this->hasCancelRequest()
                    && $cancelRequiresApproval
                    && $user?->hasPermission('sales.cancel_request', $companyId),
                'cancel_approve'  => $this->hasCancelRequest() && $user?->hasPermission('sales.cancel_approve', $companyId),
                'cancel_reject'   => $this->hasCancelRequest() && $user?->hasPermission('sales.cancel_approve', $companyId),
                'whatsapp'        => in_array($this->status, ['confirmed', 'proforma'], true)
                    && $user?->hasPermission('sales.whatsapp', $companyId),
                'convert_proforma'=> $this->isProforma() && $user?->hasPermission('sales.confirm', $companyId),
            ],
        ];
    }
}
