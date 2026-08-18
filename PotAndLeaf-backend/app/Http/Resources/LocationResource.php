<?php

namespace App\Http\Resources;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Location */
class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'code'       => $this->code,
            'type'       => $this->type,
            'is_default' => (bool) $this->is_default,
            'is_active'  => (bool) $this->is_active,
        ];
    }
}
