<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LandscapeCommission extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'project_id', 'user_id', 'staff_name', 'role', 'basis', 'percent', 'amount', 'status', 'paid_at', 'notes'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }
}
