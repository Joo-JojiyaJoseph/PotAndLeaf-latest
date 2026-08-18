<?php

namespace App\Http\Resources;

use App\Models\Company;
use App\Support\ProtectedRecords;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'code'         => $this->code,
            'legal_name'   => $this->legal_name,
            'gst_number'   => $this->gst_number,
            'state'        => $this->state,
            'state_code'   => $this->state_code,
            'address'      => $this->address,
            'locations'    => $this->locations,
            'phone'        => $this->phone,
            'email'        => $this->email,
            'logo'         => $this->logo,
            'photo'        => $this->logo,
            'description'  => $this->description,
            'is_active'    => (bool) $this->is_active,
            'is_protected' => ProtectedRecords::isProtectedCompany($this->resource),
            'users_count'  => $this->when($this->users_count !== null, $this->users_count),
        ];
    }
}
