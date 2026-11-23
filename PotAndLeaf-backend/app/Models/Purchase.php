<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    use HasAuditColumns, HasFactory, HasUuids;

    protected $fillable = [
        'company_id', 'supplier_id', 'location_id', 'purchase_no', 'invoice_no', 'invoice_date',
        'purchase_date', 'is_interstate', 'subtotal', 'discount_total', 'tax_total',
        'landed_cost_total', 'grand_total', 'supplier_payable', 'amount_paid', 'status', 'notes', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date'      => 'date',
            'purchase_date'     => 'date',
            'is_interstate'     => 'boolean',
            'subtotal'          => 'decimal:2',
            'discount_total'    => 'decimal:2',
            'tax_total'         => 'decimal:2',
            'landed_cost_total' => 'decimal:2',
            'grand_total'       => 'decimal:2',
            'supplier_payable'  => 'decimal:2',
            'confirmed_at'      => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** The purchase order this bill was made from (if any). */
    public function purchaseOrder(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PurchaseOrder::class, 'purchase_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeForCompany($query, int|string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * What this purchase adds to the supplier's outstanding: fixed at confirm
     * (see ConfirmPurchase). Older rows fall back to grand_total, which is
     * what was added for them.
     */
    /**
     * Payments that reduced this bill. `kind`: 'bill' (paid for this bill),
     * 'outstanding' (outstanding payment applied to it, oldest bill first) or
     * 'advance' (supplier advance used on it).
     *
     * @return array<int, array<string, mixed>>
     */
    public function paymentRows(): array
    {
        $rows = [];
        foreach ($this->supplierPayments as $p) {
            $rows[] = [
                'id' => 'p'.$p->id, 'payment_no' => $p->payment_no, 'payment_date' => optional($p->payment_date)->toDateString(),
                'mode' => $p->applied_from_advance ? null : $p->mode, 'reference' => $p->reference,
                'amount' => (float) $p->amount, 'kind' => $p->applied_from_advance ? 'advance' : 'bill',
            ];
        }
        foreach ($this->paymentAllocations as $a) {
            $p = $a->payment;
            if (! $p) {
                continue;
            }
            $rows[] = [
                'id' => 'a'.$a->id, 'payment_no' => $p->payment_no, 'payment_date' => optional($p->payment_date)->toDateString(),
                'mode' => $p->mode, 'reference' => $p->reference,
                'amount' => (float) $a->amount, 'kind' => $a->auto_applied ? 'outstanding' : 'bill',
            ];
        }
        usort($rows, fn ($x, $y) => [$x['payment_date'], $x['payment_no']] <=> [$y['payment_date'], $y['payment_no']]);

        return $rows;
    }

    public function supplierPayable(): float
    {
        return (float) ($this->supplier_payable ?? $this->grand_total);
    }

    public function paymentStatus(): string
    {
        if ($this->status !== 'confirmed') {
            return 'n/a';
        }
        $paid = (float) $this->amount_paid;
        $total = $this->supplierPayable();
        if ($paid <= 0) return 'unpaid';
        if ($paid + 0.01 < $total) return 'partial';
        return 'paid';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }
}
