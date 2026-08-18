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
        'discount_ceiling_percent'     => '20',    // max line/bill discount % by role default
        'reorder_alert_default'        => '10',    // default reorder level hint
        'website_integration'          => '0',     // company toggle
        'whatsapp_enabled'             => '1',     // allow sending invoices via WhatsApp
        'daily_expense'                => '0',     // flat daily expense for approx profit reports
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
