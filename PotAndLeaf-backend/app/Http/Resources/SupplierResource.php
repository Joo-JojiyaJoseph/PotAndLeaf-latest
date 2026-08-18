<?php

namespace App\Http\Resources;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Supplier */
class SupplierResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'supplier_code'     => $this->supplier_code,
            'name'              => $this->name,
            'photo'             => $this->photo,
            'email'             => $this->email,
            'phone'             => $this->phone,
            'gst_number'        => $this->gst_number,
            'pan_number'        => $this->pan_number,
            'address'           => $this->address,
            'address_line1'     => $this->address_line1,
            'address_line2'     => $this->address_line2,
            'city'              => $this->city,
            'state'             => $this->state,
            'country'           => $this->country,
            'pincode'           => $this->pincode,
            'bank_name'         => $this->bank_name,
            'bank_account_name' => $this->bank_account_name,
            'bank_account_no'   => $this->bank_account_no,
            'bank_ifsc'         => $this->bank_ifsc,
            'credit_days'     => $this->credit_days,
            'credit_limit'    => (float) $this->credit_limit,
            'opening_balance' => (float) $this->opening_balance,
            'outstanding'     => (float) $this->outstanding,
            'notes'           => $this->notes,
            'status'          => $this->status,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
            'can'             => [
                'update' => $request->user()?->can('update', $this->resource),
                'delete' => $request->user()?->can('delete', $this->resource),
            ],
        ];
    }
}
