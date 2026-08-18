<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductBrand extends Model
{
    use HasAuditColumns, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['company_id', 'code', 'name', 'description', 'status'];
}
