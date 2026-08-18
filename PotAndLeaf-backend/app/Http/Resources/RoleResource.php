<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'is_system'   => (bool) $this->is_system,
            'users_count' => $this->when(isset($this->users_count), fn () => $this->users_count),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')->values()),
            'can'         => [
                'update' => $request->user()?->can('update', $this->resource),
                'delete' => $request->user()?->can('delete', $this->resource) && ! $this->is_system,
            ],
        ];
    }
}
