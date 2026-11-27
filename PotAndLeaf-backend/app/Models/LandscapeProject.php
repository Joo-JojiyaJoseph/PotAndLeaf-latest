<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A landscaping job handled by a branch marked as a landscape division. */
class LandscapeProject extends Model
{
    use HasUuids;

    public const SERVICES = [
        'outdoor_landscaping' => 'Outdoor landscaping',
        'indoor_landscaping'  => 'Indoor landscaping',
        'contract_planting'   => 'Contract planting',
        'garden_modification' => 'Garden modification',
        'lawn'                => 'Lawn',
        'irrigation'          => 'Irrigation',
        'maintenance'         => 'Maintenance (AMC)',
        'design'              => 'Garden design',
        'installation'        => 'Planting / installation',
        'other'               => 'Other',
    ];

    public const STATUSES = ['enquiry', 'quoted', 'approved', 'in_progress', 'completed', 'cancelled'];

    protected $fillable = [
        'company_id', 'location_id', 'customer_id', 'project_no', 'title', 'customer_name', 'customer_phone',
        'service_type', 'site_address', 'site_area_sqft', 'visit_date', 'start_date', 'end_date',
        'quoted_amount', 'advance_received', 'status', 'notes', 'created_by',
        'work_order_no', 'work_order_date', 'work_order_terms', 'approved_estimate_id', 'approved_at', 'completed_at', 'completion_note',
    ];

    protected function casts(): array
    {
        return [
            'site_area_sqft'   => 'decimal:2',
            'quoted_amount'    => 'decimal:2',
            'advance_received' => 'decimal:2',
            'visit_date'       => 'date',
            'start_date'       => 'date',
            'end_date'         => 'date',
            'work_order_date'  => 'date',
            'approved_at'      => 'datetime',
            'completed_at'     => 'datetime',
        ];
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function estimates()
    {
        return $this->hasMany(LandscapeEstimate::class, 'project_id')->orderBy('revision');
    }

    public function entries()
    {
        return $this->hasMany(LandscapeWorkEntry::class, 'project_id')->orderBy('work_date')->orderBy('created_at');
    }

    public function commissions()
    {
        return $this->hasMany(LandscapeCommission::class, 'project_id');
    }
}
