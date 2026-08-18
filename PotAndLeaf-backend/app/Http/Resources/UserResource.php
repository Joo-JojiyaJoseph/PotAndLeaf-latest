<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'email'          => $this->email,
            'phone'          => $this->phone,
            'is_active'      => (bool) $this->is_active,
            'is_super_admin' => (bool) $this->is_super_admin,
            // Roles are pre-filtered to the active company by the controller.
            'roles'          => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'slug' => $r->slug,
            ])->values()),
        ];
    }
}
