<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LandscapeEstimate extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'project_id', 'revision', 'estimate_no', 'area_sqft', 'rate_per_sqft', 'planting_cost', 'service_charge', 'other_charge', 'gst_percent', 'subtotal', 'gst_amount', 'discount', 'total', 'status', 'valid_until', 'notes', 'sent_at', 'approved_at', 'created_by'];

    protected function casts(): array
    {
        return ['valid_until' => 'date', 'sent_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(LandscapeEstimateItem::class, 'estimate_id')->orderBy('sort');
    }

    public function project()
    {
        return $this->belongsTo(LandscapeProject::class, 'project_id');
    }
}
