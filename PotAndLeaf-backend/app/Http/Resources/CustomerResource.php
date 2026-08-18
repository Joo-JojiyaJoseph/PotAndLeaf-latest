<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $companyId = $this->company_id;

        return [
            'id'              => $this->id,
            'customer_code'   => $this->customer_code,
            'name'            => $this->name,
            'photo'           => $this->photo,
            'type'            => $this->type?->value,
            'email'           => $this->email,
            'phone'           => $this->phone,
            'whatsapp'        => $this->whatsapp,
            'gst_number'      => $this->gst_number,
            'address_line1'   => $this->address_line1,
            'address_line2'   => $this->address_line2,
            'city'            => $this->city,
            'state'           => $this->state,
            'pincode'         => $this->pincode,
            'credit_days'     => $this->credit_days,
            'credit_limit'    => (float) $this->credit_limit,
            'opening_balance' => (float) $this->opening_balance,
            'outstanding'     => (float) $this->outstanding,
            'loyalty_points'  => (int) $this->loyalty_points,
            'notes'           => $this->notes,
            'status'          => $this->status?->value,
            'can'             => [
                'update' => $user?->hasPermission('customers.update', $companyId),
                'delete' => $user?->hasPermission('customers.delete', $companyId),
            ],
        ];
    }
}
