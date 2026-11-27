<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LandscapeDailyStatement extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'location_id', 'statement_date', 'opening_balance', 'sites', 'other_receipts', 'other_payments', 'total_receipts', 'total_payments', 'closing_balance', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['statement_date' => 'date', 'sites' => 'array', 'other_receipts' => 'array', 'other_payments' => 'array'];
    }
}
