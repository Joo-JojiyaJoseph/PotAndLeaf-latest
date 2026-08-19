<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltyLedgerEntry;
use App\Models\Sale;
use Illuminate\Support\Carbon;

class LoyaltyService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly LoyaltyEngine $engine,
    ) {}

    /** Points a retail-style bill earns for this company (settings fallback). */
    public function pointsEarned(int|string $companyId, float $billAmount): int
    {
        return $this->engine->fallbackPointsPublic($companyId, $billAmount);
    }

    /** Points for a confirmed sale using loyalty rules engine. */
    public function pointsForSale(Sale $sale, Customer $customer): array
    {
        return $this->engine->pointsForSale($sale, $customer);
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

    public function postEarn(Customer $customer, int $points, Sale $sale, string $note = '', ?array $ruleSnapshot = null): void
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
            'rule_snapshot'  => $ruleSnapshot,
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

    /** Manual HO adjustment of customer points with ledger entry. */
    public function adjust(Customer $customer, int $pointsDelta, string $reason, ?int $userId = null): void
    {
        $customer->loyalty_points = max(0, (int) $customer->loyalty_points + $pointsDelta);
        $customer->save();

        LoyaltyLedgerEntry::create([
            'company_id'     => $customer->company_id,
            'customer_id'    => $customer->id,
            'type'           => 'adjust',
            'points'         => $pointsDelta,
            'balance_after'  => (int) $customer->loyalty_points,
            'reference_type' => 'manual',
            'reference_id'   => null,
            'note'           => trim($reason.($userId ? " (by user #{$userId})" : '')),
        ]);
    }

    /** Restore pro-rata redeemed points when original sale items are returned. */
    public function reverseRedemptionForReturn(Customer $customer, Sale $originalSale, float $returnAmount): void
    {
        $redeemed = (int) $originalSale->loyalty_points_redeemed;
        if ($redeemed <= 0 || (float) $originalSale->grand_total <= 0) {
            return;
        }

        $restore = (int) floor($redeemed * ($returnAmount / (float) $originalSale->grand_total));
        if ($restore <= 0) {
            return;
        }

        $customer->loyalty_points = (int) $customer->loyalty_points + $restore;
        $customer->save();

        LoyaltyLedgerEntry::create([
            'company_id'     => $customer->company_id,
            'customer_id'    => $customer->id,
            'type'           => 'reverse',
            'points'         => $restore,
            'balance_after'  => (int) $customer->loyalty_points,
            'reference_type' => 'sale',
            'reference_id'   => $originalSale->id,
            'note'           => 'Redemption restored on sales return',
        ]);
    }
}
