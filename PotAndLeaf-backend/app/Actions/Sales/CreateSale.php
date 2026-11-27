<?php

namespace App\Actions\Sales;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Repositories\Contracts\SaleRepositoryInterface;
use App\Services\LoyaltyService;
use App\Services\SettingsService;
use App\Support\Sales\SaleCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateSale
{
    public function __construct(
        private readonly SaleRepositoryInterface $sales,
        private readonly SaleCalculator $calculator,
        private readonly LoyaltyService $loyalty,
        private readonly SettingsService $settings,
    ) {}

    /** @param array<string,mixed> $data */
    public function handle(int|string $companyId, array $data, ?int $userId = null): Sale
    {
        $billKind = $data['bill_kind'] ?? 'tax_invoice';
        if (! in_array($billKind, ['tax_invoice', 'proforma', 'complimentary'], true)) {
            throw ValidationException::withMessages(['bill_kind' => 'Invalid bill type.']);
        }

        $this->assertDiscountCeiling($companyId, $data['items'] ?? [], $billKind);

        $computed = $this->calculator->compute($data['items'], (bool) ($data['is_interstate'] ?? false));

        // A discount typed in rupees on the whole bill is taken off the FINAL amount, after GST and
        // all other calculations. A free (complimentary) bill has nothing to discount.
        $finalDiscount = $billKind === 'complimentary' ? 0.0 : round(max(0.0, (float) ($data['discount_amount'] ?? 0)), 2);
        if ($finalDiscount > 0) {
            $this->assertFinalDiscount($companyId, $data['items'], $finalDiscount, (float) $computed['totals']['grand_total']);
            $computed['totals']['grand_total'] = round((float) $computed['totals']['grand_total'] - $finalDiscount, 2);
        }

        $customerName = $data['customer_name'] ?? null;
        $customer = null;
        if (! empty($data['customer_id'])) {
            $customer = Customer::forCompany($companyId)->find($data['customer_id']);
            $customerName = $customer?->name ?? $customerName;
        }
        $customerName ??= 'Walk-in';

        $billTotal = (float) $computed['totals']['grand_total'];
        $redeemPoints = (int) ($data['loyalty_points_redeemed'] ?? 0);
        $loyaltyDiscount = 0.0;

        if ($redeemPoints > 0) {
            if (! $customer) {
                throw ValidationException::withMessages(['loyalty_points_redeemed' => 'Select a customer to redeem loyalty points.']);
            }
            $min = $this->loyalty->minimumRedeemPoints($companyId);
            if ($min > 0 && $redeemPoints < $min) {
                throw ValidationException::withMessages([
                    'loyalty_points_redeemed' => "Redeem at least {$min} points.",
                ]);
            }
            $max = $this->loyalty->maxRedeemable($companyId, $customer, $billTotal);
            if ($redeemPoints > $max) {
                throw ValidationException::withMessages([
                    'loyalty_points_redeemed' => "Cannot redeem more than {$max} points on this bill.",
                ]);
            }
            $loyaltyDiscount = $this->loyalty->discountForPoints($companyId, $redeemPoints);
        }

        // Money taken cannot be more than the bill (after points). Change given
        // back is not "paid" — over-paying would leave the customer owing a minus amount.
        $payable = max(0, $billTotal - $loyaltyDiscount);
        if (isset($data['amount_paid']) && $data['amount_paid'] !== '' && (float) $data['amount_paid'] > $payable + 0.005) {
            throw ValidationException::withMessages([
                'amount_paid' => sprintf('Amount paid (₹%.2f) cannot be more than the bill total (₹%.2f). Enter the bill amount and give the change back.', (float) $data['amount_paid'], $payable),
            ]);
        }

        $productMeta = Product::forCompany($companyId)
            ->whereIn('id', collect($data['items'])->pluck('product_id')->filter())
            ->get(['id', 'name', 'hsn_code'])
            ->keyBy('id');

        // A retail customer always gets the retail price tier (it cannot be changed on the bill).
        $retailOnly = $customer && ($customer->type?->value ?? $customer->type) === 'retail';

        // The salesman credited with the bill (and its commission): the one chosen, else the person entering it.
        $salesmanId = filled($data['salesman_id'] ?? null) ? (int) $data['salesman_id'] : $userId;
        if ($salesmanId !== null && $salesmanId !== $userId) {
            $isStaff = \App\Models\User::query()->whereKey($salesmanId)->where('is_active', true)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('company_user')->whereColumn('company_user.user_id', 'users.id')->where('company_user.company_id', $companyId))
                ->exists();
            if (! $isStaff) {
                throw ValidationException::withMessages(['salesman_id' => 'Choose an active staff member of this company as the salesman.']);
            }
        }

        // The branch the bill sells from (default: the user's own branch, else the main location).
        $locationId = app(\App\Services\LocationStockService::class)->resolveLocationId($companyId, $data['location_id'] ?? null, $userId ? \App\Models\User::find($userId) : null);

        return DB::transaction(function () use ($companyId, $data, $computed, $customerName, $productMeta, $redeemPoints, $loyaltyDiscount, $billTotal, $billKind, $userId, $retailOnly, $locationId, $finalDiscount, $salesmanId) {
            $t = $computed['totals'];
            $due = max(0, $billTotal - $loyaltyDiscount);
            $sale = $this->sales->create([
                'company_id'              => $companyId,
                'customer_id'             => $data['customer_id'] ?? null,
                'location_id'             => $locationId,
                'customer_name'           => $customerName,
                'sale_no'                 => $this->sales->nextSaleNo($companyId),
                'sale_date'               => $data['sale_date'],
                'is_interstate'           => (bool) ($data['is_interstate'] ?? false),
                'payment_mode'            => $data['payment_mode'] ?? 'cash',
                'bill_kind'               => $billKind,
                'created_by'              => $salesmanId,
                'entered_by'              => $userId,
                'subtotal'                => $t['subtotal'],
                'tax_total'               => $t['tax_total'],
                'round_off'               => $t['round_off'],
                'grand_total'             => $t['grand_total'],
                'final_discount'          => $finalDiscount,
                'amount_paid'             => $data['amount_paid'] ?? $due,
                'loyalty_points_redeemed' => $redeemPoints,
                'loyalty_discount'        => $loyaltyDiscount,
                'status'                  => 'draft',
                'notes'                   => $data['notes'] ?? null,
            ]);

            $rows = [];
            foreach ($data['items'] as $i => $item) {
                $calc = $computed['lines'][$i];
                $meta = $productMeta[$item['product_id']] ?? null;
                $rows[] = [
                    'product_id'    => $item['product_id'],
                    'product_batch_id' => $item['product_batch_id'] ?? null,
                    'product_name'  => $meta->name ?? 'Item',
                    'hsn_code'      => $meta->hsn_code ?? null,
                    'qty'           => $item['qty'],
                    'rate'          => $item['rate'],
                    'price_level'   => $retailOnly ? 'retail' : ($item['price_level'] ?? 'retail'),
                    'discount'      => $item['discount'] ?? 0,
                    'gst_rate'      => $item['gst_rate'] ?? 0,
                    'taxable_value' => $calc['taxable_value'],
                    'cgst_amount'   => $calc['cgst_amount'],
                    'sgst_amount'   => $calc['sgst_amount'],
                    'igst_amount'   => $calc['igst_amount'],
                    'line_total'    => $calc['line_total'],
                ];
            }
            $sale->items()->createMany($rows);

            return $sale->load(['items', 'customer:id,name,type,loyalty_points']);
        });
    }

    /** The final-amount discount cannot be more than the bill total, nor break the discount ceiling. */
    private function assertFinalDiscount(int|string $companyId, array $items, float $finalDiscount, float $billTotal): void
    {
        if ($finalDiscount > $billTotal + 0.005) {
            throw ValidationException::withMessages([
                'discount_amount' => sprintf('The discount (₹%.2f) cannot be more than the bill total (₹%.2f).', $finalDiscount, $billTotal),
            ]);
        }

        $gross = 0.0;
        $lineDiscounts = 0.0;
        foreach ($items as $item) {
            $gross += (float) ($item['qty'] ?? 0) * (float) ($item['rate'] ?? 0);
            $lineDiscounts += (float) ($item['discount'] ?? 0);
        }
        $ceiling = $this->settings->getFloat($companyId, 'discount_ceiling_percent');
        if ($ceiling > 0 && $billTotal > 0 && (($lineDiscounts + $finalDiscount) / max($gross, $billTotal)) * 100 > $ceiling + 0.01) {
            throw ValidationException::withMessages([
                'discount_amount' => "Total discount exceeds the {$ceiling}% ceiling set in Settings.",
            ]);
        }
    }

    /** Enforce HO-configured max line discount as % of (qty × rate). Skipped for complimentary bills. */
    private function assertDiscountCeiling(int|string $companyId, array $items, string $billKind = 'tax_invoice'): void
    {
        if ($billKind === 'complimentary') {
            return;
        }
        $ceiling = $this->settings->getFloat($companyId, 'discount_ceiling_percent');
        if ($ceiling <= 0) {
            return;
        }

        foreach ($items as $i => $item) {
            $qty = (float) ($item['qty'] ?? 0);
            $rate = (float) ($item['rate'] ?? 0);
            $discount = (float) ($item['discount'] ?? 0);
            $base = $qty * $rate;
            if ($base <= 0) {
                continue;
            }
            $pct = ($discount / $base) * 100;
            if ($pct > $ceiling + 0.01) {
                throw ValidationException::withMessages([
                    "items.{$i}.discount" => "Discount exceeds the {$ceiling}% ceiling set in Settings.",
                ]);
            }
        }
    }
}
