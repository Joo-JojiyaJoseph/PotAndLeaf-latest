<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductUnit extends Model
{
    use HasAuditColumns, HasFactory, HasUuids;

    protected $fillable = ['company_id', 'code', 'name', 'short_name', 'description', 'status'];
}
