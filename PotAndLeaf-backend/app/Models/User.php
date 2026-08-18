<?php

namespace App\Models;

use App\Models\Concerns\HasRolesAndPermissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Clean API user — no starter-kit teams, Fortify or passkeys. Auth is Sanctum
 * personal access tokens; authorization is the company-scoped RBAC in
 * HasRolesAndPermissions.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRolesAndPermissions, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'phone', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_super_admin'    => 'boolean',
            'is_active'         => 'boolean',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function defaultCompany(): ?Company
    {
        return $this->companies()->wherePivot('is_default', true)->first()
            ?? $this->companies()->first();
    }

    /** Active users with company membership — for lists and dropdowns. */
    public function scopeActiveMembers($query, int|string|null $companyId = null)
    {
        return $query
            ->where('is_super_admin', false)
            ->where('is_active', true)
            ->whereHas('companies', fn ($q) => $companyId !== null ? $q->whereKey($companyId) : $q);
    }
}
