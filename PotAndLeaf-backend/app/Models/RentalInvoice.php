<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalInvoice extends Model
{
    use HasAuditColumns, HasUuids;

    protected $fillable = [
        'company_id', 'rental_id', 'invoice_no', 'period_from', 'period_to',
        'cycles', 'amount', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to'   => 'date',
            'cycles'      => 'decimal:2',
            'amount'      => 'decimal:2',
        ];
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
