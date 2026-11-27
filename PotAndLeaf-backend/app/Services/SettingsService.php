<?php

namespace App\Services;

use App\Models\CompanySetting;

/** Company-scoped settings with sensible defaults for loyalty & sales. */
class SettingsService
{
    public const DEFAULTS = [
        'loyalty_earn_rupees'          => '100',   // ₹ spent per point earned
        'loyalty_earn_points'          => '1',     // points awarded per earn unit
        'loyalty_redeem_rupees'        => '1',     // ₹ discount per point redeemed
        'loyalty_redeem_cap_percent'   => '50',    // max % of bill redeemable
        'loyalty_redeem_min_points'    => '0',     // minimum points per redemption; 0 disables the floor
        'loyalty_points_expiry_days'   => '0',     // days until earned points expire; 0 keeps them
        'discount_ceiling_percent'     => '20',    // max line/bill discount % by role default
        'landed_cost_payable_to_supplier' => '1',  // 1 = freight/loading on a purchase is owed to the supplier; 0 = paid to someone else
        'reorder_alert_default'        => '10',    // default reorder level hint
        'website_integration'          => '0',     // company toggle
        'whatsapp_enabled'             => '1',     // allow sending invoices via WhatsApp
        'daily_expense'                => '0',     // flat daily expense for approx profit reports
        'sale_care_whatsapp'           => '0',     // after a sale is confirmed, WhatsApp the customer the care guide of what they bought
        'rental_auto_bill'             => '1',     // generate invoices on billing cycle via scheduler
        'rental_whatsapp_on_bill'      => '1',     // queue WhatsApp when auto-billing creates an invoice
        'rental_payment_due_days'      => '7',     // days after period end before invoice is due
        'rental_overdue_alert_days'    => '0',     // grace days after due/expected return before alerting
        'rental_estimate_valid_days'   => '7',     // days an estimate given to a customer stays valid
        'rental_terms'                 => "1. Rent is charged for each billing cycle (daily, weekly or monthly) that the plants are with the customer. Any part of a cycle counts as a full cycle.\n2. A refundable security deposit is taken when the plants are handed over. A deposit is optional and is taken only if it is agreed. If a deposit was taken, unpaid rent and the charges for damaged or missing plants are deducted from it, and the balance is refunded when the rental is settled. Anything above the deposit is billed to the customer.\n3. A damaged plant is charged at half its retail price and a missing plant at its full retail price.\n4. The customer is responsible for the plants from delivery until they are returned, and must look after them as advised (light and watering). The plants must not be sold, sub-let or used for any other purpose.\n5. The plants must be returned on or before the expected return date. Rent continues for every extra day they are kept.\n6. Rent bills must be paid within the due days shown on the bill.\n7. This estimate is not a tax invoice. The amounts can change if the plants, quantities or rental period change.",   // terms & conditions printed on the rental estimate
        'rental_reminder_lead_days'    => '3',     // days before return/payment due to send a reminder
        'sms_enabled'                  => '0',     // send rental reminders/overdue alerts via SMS
        'sale_cancel_requires_approval'=> '1',     // confirmed sales need HO approval to cancel
        'cash_opening_balance'         => '0',     // opening cash balance for cash book
        'bank_opening_balance'         => '0',     // opening bank balance for bank book
        'financial_year_start_month'   => '4',     // April FY start (1=Jan … 12=Dec)
        'dead_stock_days'              => '180',   // days without sale for dead stock
        'eod_management_enabled'       => '0',     // HO management EOD summary
        'eod_management_whatsapp_phones' => '',    // comma-separated HO/manager phones
        'eod_management_email_recipients' => '',   // comma-separated HO/manager emails
        'eod_management_send_time'     => '20:30', // company-local send time (HH:MM)
        'eod_commission_send_time'     => '20:00', // staff commission WhatsApp time (HH:MM)
        'company_timezone'             => 'Asia/Kolkata',
        'seasonal_care_rate_limit'     => '40',    // max seasonal care WhatsApp sends per company per day
    ];

    /** @return array<string, string> */
    public function all(int|string $companyId): array
    {
        $stored = CompanySetting::query()
            ->where('company_id', $companyId)
            ->pluck('value', 'key')
            ->all();

        return array_merge(self::DEFAULTS, $stored);
    }

    public function get(int|string $companyId, string $key, ?string $default = null): string
    {
        $all = $this->all($companyId);

        return (string) ($all[$key] ?? $default ?? self::DEFAULTS[$key] ?? '');
    }

    public function getFloat(int|string $companyId, string $key): float
    {
        return (float) $this->get($companyId, $key);
    }

    public function getInt(int|string $companyId, string $key): int
    {
        return (int) $this->get($companyId, $key);
    }

    /** @param array<string, mixed> $values */
    public function setMany(int|string $companyId, array $values): array
    {
        $allowed = array_keys(self::DEFAULTS);
        foreach ($values as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }
            CompanySetting::query()->updateOrCreate(
                ['company_id' => $companyId, 'key' => $key],
                ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value],
            );
        }

        return $this->all($companyId);
    }
}
