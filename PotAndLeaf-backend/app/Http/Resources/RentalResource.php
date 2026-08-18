<?php

namespace App\Http\Resources;

use App\Models\Rental;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Rental */
class RentalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $companyId = $this->company_id;

        return [
            'id'                => $this->id,
            'company_id'        => $this->company_id,
            'rental_no'         => $this->rental_no,
            'customer_id'       => $this->customer_id,
            'customer_name'     => $this->customer?->name,
            'company'           => $this->whenLoaded('company', fn () => [
                'name' => $this->company->name, 'legal_name' => $this->company->legal_name,
                'gst_number' => $this->company->gst_number, 'address' => $this->company->address,
                'phone' => $this->company->phone, 'state' => $this->company->state, 'state_code' => $this->company->state_code,
            ]),
            'start_date'        => optional($this->start_date)->toDateString(),
            'expected_end_date' => optional($this->expected_end_date)->toDateString(),
            'billing_cycle'     => $this->billing_cycle,
            'deposit'           => (float) $this->deposit,
            'rental_charge'     => (float) $this->rental_charge,
            'damage_charge'     => (float) $this->damage_charge,
            'missing_charge'    => (float) $this->missing_charge,
            'refund_amount'     => (float) $this->refund_amount,
            'balance_due'       => (float) $this->balance_due,
            'return_date'       => optional($this->return_date)->toDateString(),
            'settled_at'        => optional($this->settled_at)->toIso8601String(),
            'status'            => $this->status,
            'notes'             => $this->notes,
            'activated_at'      => optional($this->activated_at)->toIso8601String(),
            'returned_at'       => optional($this->returned_at)->toIso8601String(),
            'items_count'       => $this->when($this->items_count !== null, $this->items_count),
            'items'             => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'product_name' => $i->product_name,
                'qty' => (float) $i->qty, 'rate_per_cycle' => (float) $i->rate_per_cycle,
                'returned_qty' => (float) $i->returned_qty, 'damaged_qty' => (float) $i->damaged_qty, 'missing_qty' => (float) $i->missing_qty,
                'outstanding_qty' => (float) $i->qty - (float) $i->returned_qty - (float) $i->damaged_qty - (float) $i->missing_qty,
            ])->values()),
            'invoices'          => $this->whenLoaded('invoices', fn () => $this->invoices->map(fn ($inv) => [
                'id' => $inv->id, 'invoice_no' => $inv->invoice_no,
                'period_from' => optional($inv->period_from)->toDateString(), 'period_to' => optional($inv->period_to)->toDateString(),
                'cycles' => (float) $inv->cycles, 'amount' => (float) $inv->amount, 'status' => $inv->status,
            ])->values()),
            'can'               => [
                'activate' => $this->status === 'draft' && $user?->hasPermission('rental.activate', $companyId),
                'settle'   => $this->status === 'active' && $user?->hasPermission('rental.return', $companyId),
                'return'   => $this->status === 'active' && $user?->hasPermission('rental.return', $companyId),
                'cancel'   => in_array($this->status, ['draft', 'active'], true) && $user?->hasPermission('rental.delete', $companyId),
                'bill'     => in_array($this->status, ['active', 'returned'], true) && $user?->hasPermission('rental.bill', $companyId),
            ],
        ];
    }
}
