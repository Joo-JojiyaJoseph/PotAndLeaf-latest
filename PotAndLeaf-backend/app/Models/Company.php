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
        'name', 'code', 'legal_name', 'gst_number', 'state', 'state_code','locations',
        'address', 'phone', 'email', 'is_active', 'is_protected', 'logo', 'description',
    ];

    protected function casts(): array
    {
        return [
            'is_active'    => 'boolean',
            'is_protected' => 'boolean',
        ];
    }

    /**
     * Each company has exactly one location (its shop / godown). It is created
     * with the company, so nobody has to set up locations separately.
     */
    protected static function booted(): void
    {
        static::created(fn (Company $company) => $company->ensureMainLocation());
        static::restored(fn (Company $company) => $company->ensureMainLocation());
    }

    public function ensureMainLocation(): Location
    {
        $main = Location::query()->where('company_id', $this->id)
            ->orderByDesc('is_default')->orderByDesc('is_active')->orderBy('created_at')->first();

        if (! $main) {
            return Location::create([
                'company_id' => $this->id, 'name' => $this->name, 'code' => 'MAIN',
                'type' => 'shop', 'is_default' => true, 'is_active' => true,
            ]);
        }

        if (! $main->is_default || ! $main->is_active) {
            $main->forceFill(['is_default' => true, 'is_active' => true])->save();
        }

        return $main;
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
