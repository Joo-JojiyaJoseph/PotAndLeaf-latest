<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalNotificationLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'rental_id', 'rental_invoice_id', 'channel', 'event',
        'recipient', 'status', 'message', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(RentalInvoice::class, 'rental_invoice_id');
    }
}
