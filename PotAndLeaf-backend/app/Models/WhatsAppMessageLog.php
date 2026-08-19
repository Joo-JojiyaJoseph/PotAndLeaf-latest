<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WhatsAppMessageLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'recipient_type', 'recipient_id', 'recipient_phone',
        'message_type', 'message', 'status', 'error', 'retry_count', 'business_date', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'sent_at'       => 'datetime',
        ];
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
