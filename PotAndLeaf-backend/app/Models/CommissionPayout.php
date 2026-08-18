<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommissionPayout extends Model
{
    use HasAuditColumns, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'user_id', 'period', 'sales_total', 'amount',
        'mode', 'payment_date', 'reference', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'sales_total'  => 'decimal:2',
            'amount'       => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
