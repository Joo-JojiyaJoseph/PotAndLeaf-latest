<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'legal_name', 'gst_number', 'state', 'state_code',
        'address', 'phone', 'email', 'is_active', 'is_protected', 'logo', 'description',
    ];

    protected function casts(): array
    {
        return [
            'is_active'    => 'boolean',
            'is_protected' => 'boolean',
        ];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
