<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LandscapeWorkEntry extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'project_id', 'work_date', 'type', 'category', 'name', 'qty', 'rate', 'amount', 'notes', 'created_by', 'statement_id'];

    protected function casts(): array
    {
        return ['work_date' => 'date'];
    }
}
