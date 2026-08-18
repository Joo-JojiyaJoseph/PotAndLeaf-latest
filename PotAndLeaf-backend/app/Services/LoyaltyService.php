<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltyLedgerEntry;
use App\Models\Sale;

class LoyaltyService
{
    public function __construct(private readonly SettingsService $settings) {}

    /** Points a retail-style bill earns for this company. */
    public function pointsEarned(int|string $companyId, float $billAmount): int
    {
        $rupees = max(1, $this->settings->getInt($companyId, 'loyalty_earn_rupees'));
        $per = max(1, $this->settings->getInt($companyId, 'loyalty_earn_points'));

        return (int) floor($billAmount / $rupees) * $per;
    }

    /** Max redeemable points for a given bill amount & customer balance. */
    public function maxRedeemable(int|string $companyId, Customer $customer, float $billAmount): int
    {
        $rate = max(0.01, $this->settings->getFloat($companyId, 'loyalty_redeem_rupees'));
        $capPct = min(100, max(0, $this->settings->getFloat($companyId, 'loyalty_redeem_cap_percent')));
        $capRupees = $billAmount * ($capPct / 100);
        $capPoints = (int) floor($capRupees / $rate);

        return max(0, min((int) $customer->loyalty_points, $capPoints));
    }

    public function discountForPoints(int|string $companyId, int $points): float
    {
        $rate = max(0.01, $this->settings->getFloat($companyId, 'loyalty_redeem_rupees'));

        return round($points * $rate, 2);
    }

    public function postEarn(Customer $customer, int $points, Sale $sale, string $note = ''): void
    {
        if ($points <= 0) {
            return;
        }
        $customer->loyalty_points = (int) $customer->loyalty_points + $points;
        $customer->save();

        LoyaltyLedgerEntry::create([
            'company_id'     => $customer->company_id,
            'customer_id'    => $customer->id,
            'type'           => 'earn',
            'points'         => $points,
            'balance_after'  => (int) $customer->loyalty_points,
            'reference_type' => 'sale',
            'reference_id'   => $sale->id,
            'note'           => $note ?: "Earned on {$sale->sale_no}",
        ]);
    }

    public function postRedeem(Customer $customer, int $points, Sale $sale): void
    {
        if ($points <= 0) {
            return;
        }
        $customer->loyalty_points = max(0, (int) $customer->loyalty_points - $points);
        $customer->save();

        LoyaltyLedgerEntry::create([
            'company_id'     => $customer->company_id,
            'customer_id'    => $customer->id,
            'type'           => 'redeem',
            'points'         => -$points,
            'balance_after'  => (int) $customer->loyalty_points,
            'reference_type' => 'sale',
            'reference_id'   => $sale->id,
            'note'           => "Redeemed on {$sale->sale_no}",
        ]);
    }

    public function reverseForSale(Customer $customer, Sale $sale): void
    {
        $entries = LoyaltyLedgerEntry::query()
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->get();

        if ($entries->isEmpty()) {
            // Legacy sales without ledger — approximate reverse of earn only
            $approx = $this->pointsEarned($customer->company_id, (float) $sale->grand_total);
            if ($approx > 0) {
                $customer->loyalty_points = max(0, (int) $customer->loyalty_points - $approx);
                $customer->save();
            }

            return;
        }

        $net = (int) $entries->sum('points'); // earn positive, redeem negative
        $customer->loyalty_points = max(0, (int) $customer->loyalty_points - $net);
        $customer->save();

        LoyaltyLedgerEntry::create([
            'company_id'     => $customer->company_id,
            'customer_id'    => $customer->id,
            'type'           => 'reverse',
            'points'         => -$net,
            'balance_after'  => (int) $customer->loyalty_points,
            'reference_type' => 'sale',
            'reference_id'   => $sale->id,
            'note'           => "Reversal of {$sale->sale_no}",
        ]);
    }
}
