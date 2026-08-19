<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EodManagementLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'channel', 'recipient', 'business_date', 'status', 'error', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'sent_at'       => 'datetime',
        ];
    }
}
