<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasAuditColumns, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'customer_code', 'name', 'type', 'email', 'phone', 'whatsapp',
        'gst_number', 'address_line1', 'address_line2', 'city', 'state', 'pincode',
        'credit_days', 'credit_limit', 'opening_balance', 'outstanding',
        'loyalty_points', 'notes', 'status', 'photo',];

    protected function casts(): array
    {
        return [
            'type'            => CustomerType::class,
            'status'          => CustomerStatus::class,
            'credit_limit'    => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'outstanding'     => 'decimal:2',
            'loyalty_points'  => 'integer',
        ];
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeSearch($query, ?string $term)
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('customer_code', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('gst_number', 'like', "%{$term}%");
        });
    }
}
