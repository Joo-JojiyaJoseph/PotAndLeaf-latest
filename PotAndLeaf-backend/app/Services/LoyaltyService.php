<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltyLedgerEntry;
use App\Models\Sale;
use App\Models\SalesReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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

    public function minimumRedeemPoints(int|string $companyId): int
    {
        return max(0, $this->settings->getInt($companyId, 'loyalty_redeem_min_points'));
    }

    /** Max redeemable points for a given bill amount & customer balance. */
    public function maxRedeemable(int|string $companyId, Customer $customer, float $billAmount): int
    {
        $balance = (int) $customer->loyalty_points;
        $min = $this->minimumRedeemPoints($companyId);
        if ($min > 0 && $balance < $min) {
            return 0;
        }

        $rate = max(0.01, $this->settings->getFloat($companyId, 'loyalty_redeem_rupees'));
        $capPct = min(100, max(0, $this->settings->getFloat($companyId, 'loyalty_redeem_cap_percent')));
        $capRupees = $billAmount * ($capPct / 100);
        $capPoints = (int) floor($capRupees / $rate);
        $redeemable = max(0, min($balance, $capPoints));

        if ($min > 0 && $redeemable > 0 && $redeemable < $min) {
            return 0;
        }

        return $redeemable;
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

        DB::transaction(function () use ($customer, $points, $sale, $note, $ruleSnapshot) {
            $locked = $this->lockCustomer($customer);
            $locked->loyalty_points = (int) $locked->loyalty_points + $points;
            $locked->save();
            $customer->loyalty_points = (int) $locked->loyalty_points;

            $days = $this->settings->getInt($locked->company_id, 'loyalty_points_expiry_days');

            LoyaltyLedgerEntry::create([
                'company_id'       => $locked->company_id,
                'customer_id'      => $locked->id,
                'type'             => 'earn',
                'points'           => $points,
                'remaining_points' => $points,
                'balance_after'    => (int) $locked->loyalty_points,
                'reference_type'   => 'sale',
                'reference_id'     => $sale->id,
                'note'             => $note ?: "Earned on {$sale->sale_no}",
                'rule_snapshot'    => $ruleSnapshot,
                'expires_at'       => $days > 0 ? now()->addDays($days)->toDateString() : null,
            ]);
        });
    }

    public function postRedeem(Customer $customer, int $points, Sale $sale): void
    {
        if ($points <= 0) {
            return;
        }

        DB::transaction(function () use ($customer, $points, $sale) {
            $locked = $this->lockCustomer($customer);
            if ((int) $locked->loyalty_points < $points) {
                throw ValidationException::withMessages([
                    'loyalty_points_redeemed' => 'Customer no longer has enough loyalty points.',
                ]);
            }

            $this->consumeEarnLots($locked, $points);
            $locked->loyalty_points = (int) $locked->loyalty_points - $points;
            $locked->save();
            $customer->loyalty_points = (int) $locked->loyalty_points;

            LoyaltyLedgerEntry::create([
                'company_id'     => $locked->company_id,
                'customer_id'    => $locked->id,
                'type'           => 'redeem',
                'points'         => -$points,
                'balance_after'  => (int) $locked->loyalty_points,
                'reference_type' => 'sale',
                'reference_id'   => $sale->id,
                'note'           => "Redeemed on {$sale->sale_no}",
            ]);
        });
    }

    public function reverseForSale(Customer $customer, Sale $sale): void
    {
        DB::transaction(function () use ($customer, $sale) {
            $locked = $this->lockCustomer($customer);
            $entries = LoyaltyLedgerEntry::query()
                ->where('company_id', $locked->company_id)
                ->where('customer_id', $locked->id)
                ->where('reference_type', 'sale')
                ->where('reference_id', $sale->id)
                ->get();

            if ($entries->isEmpty()) {
                $approx = $this->pointsEarned(
                    $locked->company_id,
                    max(0, (float) $sale->grand_total - (float) $sale->loyalty_discount),
                );
                if ($approx > 0) {
                    $locked->loyalty_points = max(0, (int) $locked->loyalty_points - $approx);
                    $locked->save();
                    $customer->loyalty_points = (int) $locked->loyalty_points;
                }

                return;
            }

            $entries->where('type', 'earn')->each(function (LoyaltyLedgerEntry $entry) {
                $entry->update(['remaining_points' => 0, 'expires_processed_at' => now()]);
            });

            $net = (int) $entries->sum('points');
            $locked->loyalty_points = max(0, (int) $locked->loyalty_points - $net);
            $locked->save();
            $customer->loyalty_points = (int) $locked->loyalty_points;

            LoyaltyLedgerEntry::create([
                'company_id'     => $locked->company_id,
                'customer_id'    => $locked->id,
                'type'           => 'reverse',
                'points'         => -$net,
                'balance_after'  => (int) $locked->loyalty_points,
                'reference_type' => 'sale',
                'reference_id'   => $sale->id,
                'note'           => "Reversal of {$sale->sale_no}",
            ]);
        });
    }

    /** Manual HO adjustment of customer points with ledger entry. */
    public function adjust(Customer $customer, int $pointsDelta, string $reason, ?int $userId = null): void
    {
        DB::transaction(function () use ($customer, $pointsDelta, $reason, $userId) {
            $locked = $this->lockCustomer($customer);
            if ($pointsDelta < 0) {
                $this->consumeEarnLots($locked, abs($pointsDelta));
            }
            $locked->loyalty_points = max(0, (int) $locked->loyalty_points + $pointsDelta);
            $locked->save();
            $customer->loyalty_points = (int) $locked->loyalty_points;

            LoyaltyLedgerEntry::create([
                'company_id'       => $locked->company_id,
                'customer_id'      => $locked->id,
                'type'             => 'adjust',
                'points'           => $pointsDelta,
                'remaining_points' => $pointsDelta > 0 ? $pointsDelta : null,
                'balance_after'    => (int) $locked->loyalty_points,
                'reference_type'   => 'manual',
                'reference_id'     => null,
                'note'             => trim($reason.($userId ? " (by user #{$userId})" : '')),
                'expires_at'       => $pointsDelta > 0 && $this->settings->getInt($locked->company_id, 'loyalty_points_expiry_days') > 0
                    ? now()->addDays($this->settings->getInt($locked->company_id, 'loyalty_points_expiry_days'))->toDateString()
                    : null,
            ]);
        });
    }

    /**
     * Take back the points a sale earned, in proportion to what is returned.
     * Uses the points the sale actually earned (rules included), not the
     * default earn rate, and never takes back more than the sale earned
     * across all its returns. Returns the points removed.
     */
    public function reverseEarnForReturn(Customer $customer, Sale $sale, SalesReturn $return): int
    {
        $earned = (int) LoyaltyLedgerEntry::query()
            ->where('company_id', $sale->company_id)
            ->where('customer_id', $customer->id)
            ->where('type', 'earn')
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->sum('points');

        $saleTotal = (float) $sale->grand_total;
        if ($earned <= 0 || $saleTotal <= 0) {
            return 0;
        }

        $points = (int) floor($earned * min(1.0, (float) $return->grand_total / $saleTotal));

        $returnIds = SalesReturn::query()->where('sale_id', $sale->id)->pluck('id');
        $alreadyBack = (int) abs((int) LoyaltyLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->where('type', 'reverse')
            ->where('reference_type', 'sales-return')
            ->whereIn('reference_id', $returnIds)
            ->sum('points'));
        $points = min($points, max(0, $earned - $alreadyBack));
        if ($points <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($customer, $return, $points) {
            $locked = $this->lockCustomer($customer);
            $points = min($points, (int) $locked->loyalty_points);
            if ($points <= 0) {
                return 0;
            }
            // Take them from the oldest open lots so expiry stays correct.
            $this->consumeEarnLots($locked, $points);
            $locked->loyalty_points = (int) $locked->loyalty_points - $points;
            $locked->save();
            $customer->loyalty_points = (int) $locked->loyalty_points;

            LoyaltyLedgerEntry::create([
                'company_id'     => $locked->company_id,
                'customer_id'    => $locked->id,
                'type'           => 'reverse',
                'points'         => -$points,
                'balance_after'  => (int) $locked->loyalty_points,
                'reference_type' => 'sales-return',
                'reference_id'   => $return->id,
                'note'           => "Return {$return->return_no}",
            ]);

            return $points;
        });
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

        DB::transaction(function () use ($customer, $originalSale, $restore) {
            $locked = $this->lockCustomer($customer);
            $locked->loyalty_points = (int) $locked->loyalty_points + $restore;
            $locked->save();
            $customer->loyalty_points = (int) $locked->loyalty_points;

            LoyaltyLedgerEntry::create([
                'company_id'       => $locked->company_id,
                'customer_id'      => $locked->id,
                'type'             => 'reverse',
                'points'           => $restore,
                'remaining_points' => $restore,
                'balance_after'    => (int) $locked->loyalty_points,
                'reference_type'   => 'sale',
                'reference_id'     => $originalSale->id,
                'note'             => 'Redemption restored on sales return',
            ]);
        });
    }

    /** Expire earn lots whose expires_at has passed. Returns the number of lots processed. */
    public function expireDuePoints(int|string $companyId, ?string $asOf = null): int
    {
        $asOf ??= now()->toDateString();
        $processed = 0;

        LoyaltyLedgerEntry::query()
            ->where('company_id', $companyId)
            ->where('type', 'earn')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $asOf)
            ->whereNull('expires_processed_at')
            ->orderBy('expires_at')
            ->each(function (LoyaltyLedgerEntry $earn) use (&$processed) {
                DB::transaction(function () use ($earn, &$processed) {
                    $row = LoyaltyLedgerEntry::query()->whereKey($earn->id)->lockForUpdate()->first();
                    if (! $row || $row->expires_processed_at) {
                        return;
                    }

                    $customer = Customer::query()->whereKey($row->customer_id)->lockForUpdate()->first();
                    if (! $customer) {
                        $row->update(['expires_processed_at' => now(), 'remaining_points' => 0]);

                        return;
                    }

                    $qty = min((int) ($row->remaining_points ?? $row->points), (int) $customer->loyalty_points);
                    if ($qty > 0) {
                        $customer->loyalty_points = (int) $customer->loyalty_points - $qty;
                        $customer->save();
                        LoyaltyLedgerEntry::create([
                            'company_id'     => $customer->company_id,
                            'customer_id'    => $customer->id,
                            'type'           => 'expire',
                            'points'         => -$qty,
                            'balance_after'  => (int) $customer->loyalty_points,
                            'reference_type' => 'loyalty_ledger',
                            'reference_id'   => $row->id,
                            'note'           => 'Points expired',
                        ]);
                    }

                    $row->update(['remaining_points' => 0, 'expires_processed_at' => now()]);
                    $processed++;
                    Log::info('loyalty.expired', [
                        'company_id'  => $customer->company_id,
                        'customer_id' => $customer->id,
                        'earn_id'     => $row->id,
                        'points'      => $qty,
                    ]);
                });
            });

        return $processed;
    }

    private function lockCustomer(Customer $customer): Customer
    {
        return Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
    }

    /** Reduce the oldest open earn lots so expiry does not remove points already redeemed. */
    private function consumeEarnLots(Customer $customer, int $points): void
    {
        $left = $points;
        $lots = LoyaltyLedgerEntry::query()
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->where('type', 'earn')
            ->where('remaining_points', '>', 0)
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, (int) $lot->remaining_points);
            $lot->remaining_points = (int) $lot->remaining_points - $take;
            $lot->save();
            $left -= $take;
        }
    }
}
