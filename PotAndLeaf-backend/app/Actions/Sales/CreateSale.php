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
            $max = $this->loyalty->maxRedeemable($companyId, $customer, $billTotal);
            if ($redeemPoints > $max) {
                throw ValidationException::withMessages([
                    'loyalty_points_redeemed' => "Cannot redeem more than {$max} points on this bill.",
                ]);
            }
            $loyaltyDiscount = $this->loyalty->discountForPoints($companyId, $redeemPoints);
        }

        $productMeta = Product::forCompany($companyId)
            ->whereIn('id', collect($data['items'])->pluck('product_id')->filter())
            ->get(['id', 'name', 'hsn_code'])
            ->keyBy('id');

        return DB::transaction(function () use ($companyId, $data, $computed, $customerName, $productMeta, $redeemPoints, $loyaltyDiscount, $billTotal, $billKind) {
            $t = $computed['totals'];
            $due = max(0, $billTotal - $loyaltyDiscount);
            $sale = $this->sales->create([
                'company_id'              => $companyId,
                'customer_id'             => $data['customer_id'] ?? null,
                'location_id'             => $data['location_id'] ?? null,
                'customer_name'           => $customerName,
                'sale_no'                 => $this->sales->nextSaleNo($companyId),
                'sale_date'               => $data['sale_date'],
                'is_interstate'           => (bool) ($data['is_interstate'] ?? false),
                'payment_mode'            => $data['payment_mode'] ?? 'cash',
                'bill_kind'               => $billKind,
                'subtotal'                => $t['subtotal'],
                'tax_total'               => $t['tax_total'],
                'round_off'               => $t['round_off'],
                'grand_total'             => $t['grand_total'],
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
                    'price_level'   => $item['price_level'] ?? 'retail',
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
