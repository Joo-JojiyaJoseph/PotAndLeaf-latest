<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LandscapeEstimateItem extends Model
{
    use HasUuids;

    protected $fillable = ['estimate_id', 'kind', 'name', 'product_id', 'qty', 'unit', 'rate', 'amount', 'sort'];

    public $timestamps = false;
}
