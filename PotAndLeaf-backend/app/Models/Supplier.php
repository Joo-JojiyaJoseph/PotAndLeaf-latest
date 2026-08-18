<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'supplier_code',
        'name',
        'email',
        'phone',
        'gst_number',
        'pan_number',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'country',
        'pincode',
        'bank_name',
        'bank_account_no',
        'bank_ifsc',
        'credit_days',
        'credit_limit',
        'opening_balance',
        'outstanding',
        'notes',
        'status', 'photo', 'bank_account_name', 'address',];

    /**
     * Sensitive statutory / banking fields are encrypted at rest.
     * They decrypt transparently on read for authorised users.
     */
    protected function casts(): array
    {
        return [
            'gst_number' => 'encrypted',
            'pan_number' => 'encrypted',
            'bank_account_no' => 'encrypted',
            'credit_days' => 'integer',
            'credit_limit' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'outstanding' => 'decimal:2',
        ];
    }

    // Relationships -------------------------------------------------------

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Query scopes --------------------------------------------------------

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeSearch($query, ?string $term)
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('supplier_code', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%");
        });
    }
}
