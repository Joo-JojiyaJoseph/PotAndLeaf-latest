<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyApiKey extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'name', 'key_prefix', 'key_hash', 'scopes',
        'last_used_at', 'expires_at', 'revoked_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scopes'       => 'array',
            'last_used_at' => 'datetime',
            'expires_at'   => 'datetime',
            'revoked_at'   => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isUsable(): bool
    {
        if ($this->revoked_at) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public static function generatePlaintext(): string
    {
        return 'plk_'.bin2hex(random_bytes(24));
    }

    public static function hashKey(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
