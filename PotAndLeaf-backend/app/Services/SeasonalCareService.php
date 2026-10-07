<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SeasonalCareRule;
use App\Models\SeasonalCareSend;
use App\Models\WhatsAppMessageLog;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Season\IndianSeason;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SeasonalCareService
{
    public function __construct(
        private readonly WhatsAppService $whatsapp,
        private readonly SettingsService $settings,
    ) {}

    /** Find purchase-history matches and send seasonal care WhatsApp messages. */
    public function sendDueMessages(int|string $companyId, ?string $asOf = null): array
    {
        if ($this->settings->get($companyId, 'whatsapp_enabled') !== '1') {
            return ['sent' => 0, 'skipped' => 0];
        }

        $asOf ??= now()->toDateString();
        $month = (int) Carbon::parse($asOf)->format('n');
        $season = IndianSeason::forDate($asOf);
        $sent = 0;
        $skipped = 0;
        $limit = max(1, (int) ($this->settings->get($companyId, 'seasonal_care_rate_limit') ?: 40));
        $alreadyToday = WhatsAppMessageLog::forCompany($companyId)
            ->where('message_type', 'seasonal_care')
            ->whereDate('business_date', $asOf)
            ->where('status', 'sent')
            ->count();
        $remaining = max(0, $limit - $alreadyToday);
        if ($remaining === 0) {
            Log::info('seasonal_care.rate_limited', ['company_id' => $companyId, 'date' => $asOf]);

            return ['sent' => 0, 'skipped' => 0];
        }

        $companyName = Company::query()->find($companyId)?->name ?? '';
        $rules = SeasonalCareRule::forCompany($companyId)->where('is_active', true)->get();

        foreach ($rules as $rule) {
            if ($sent >= $remaining) {
                break;
            }
            if ($rule->season && $rule->season !== $season) {
                continue;
            }
            if ($rule->season_months && ! in_array($month, $rule->season_months, true)) {
                continue;
            }
            if (($rule->timing ?? 'days_after_purchase') === 'season_start') {
                $batch = $this->sendSeasonStart($rule, $companyId, $asOf, $companyName, $remaining - $sent);
                $sent += $batch['sent'];
                $skipped += $batch['skipped'];
                continue;
            }

            $triggerDate = Carbon::parse($asOf)->subDays($rule->days_after_purchase)->toDateString();

            $sales = Sale::forCompany($companyId)
                ->where('status', 'confirmed')
                ->whereDate('sale_date', $triggerDate)
                ->with(['customer:id,name,phone,whatsapp,whatsapp_opt_in', 'items.product:id,name,category_id'])
                ->get();

            foreach ($sales as $sale) {
                if (! $sale->customer_id) {
                    continue;
                }

                $matchingItems = $sale->items->filter(function ($item) use ($rule) {
                    if ($rule->product_id && $rule->product_id !== $item->product_id) {
                        return false;
                    }
                    if ($rule->category_id && $item->product?->category_id !== $rule->category_id) {
                        return false;
                    }

                    return $rule->product_id || $rule->category_id;
                });

                if ($matchingItems->isEmpty()) {
                    continue;
                }

                if (SeasonalCareSend::where('seasonal_care_rule_id', $rule->id)
                    ->where('customer_id', $sale->customer_id)
                    ->where('sale_id', $sale->id)
                    ->exists()) {
                    $skipped++;
                    continue;
                }

                $maxSends = (int) ($rule->max_sends_per_customer ?? 0);
                if ($maxSends > 0) {
                    $already = SeasonalCareSend::where('seasonal_care_rule_id', $rule->id)
                        ->where('customer_id', $sale->customer_id)
                        ->count();
                    if ($already >= $maxSends) {
                        $skipped++;
                        continue;
                    }
                }

                $customer = $sale->customer;
                if ($customer && $customer->whatsapp_opt_in === false) {
                    $skipped++;
                    continue;
                }
                $phone = $customer?->whatsapp ?: $customer?->phone;
                if (! $this->validPhone($phone)) {
                    $skipped++;
                    continue;
                }
                if ($sent >= $remaining) {
                    break 2;
                }

                $productName = $matchingItems->first()->product_name ?? $matchingItems->first()->product?->name ?? 'your plant';
                $message = str_replace(
                    ['{customer_name}', '{product_name}', '{company_name}'],
                    [$customer->name, $productName, $companyName],
                    $rule->message_template,
                );

                $result = $this->whatsapp->sendMessage($phone, $message);

                WhatsAppMessageLog::create([
                    'company_id'      => $companyId,
                    'recipient_type'  => 'customer',
                    'recipient_id'    => $sale->customer_id,
                    'recipient_phone' => $phone,
                    'message_type'    => 'seasonal_care',
                    'message'         => $message,
                    'status'          => $result['success'] ? 'sent' : 'failed',
                    'error'           => $result['success'] ? null : ($result['message'] ?? null),
                    'business_date'   => $asOf,
                    'sent_at'         => $result['success'] ? now() : null,
                ]);

                if ($result['success']) {
                    SeasonalCareSend::create([
                        'seasonal_care_rule_id' => $rule->id,
                        'customer_id'           => $sale->customer_id,
                        'sale_id'               => $sale->id,
                        'product_id'            => $matchingItems->first()->product_id,
                        'season'                => $season,
                        'season_year'           => IndianSeason::yearKey($asOf),
                        'sent_at'               => now(),
                    ]);
                    $sent++;
                } else {
                    $skipped++;
                }
            }
        }

        Log::info('seasonal_care.completed', ['company_id' => $companyId, 'date' => $asOf, 'sent' => $sent, 'skipped' => $skipped]);

        return compact('sent', 'skipped');
    }

    /** @return array{sent: int, skipped: int} */
    private function sendSeasonStart(SeasonalCareRule $rule, int|string $companyId, string $asOf, string $companyName, int $budget): array
    {
        $sent = 0;
        $skipped = 0;
        if ($budget <= 0 || ! IndianSeason::isStartWindow($asOf)) {
            return compact('sent', 'skipped');
        }

        $season = $rule->season ?: IndianSeason::forDate($asOf);
        $year = IndianSeason::yearKey($asOf);
        $since = Carbon::parse($asOf)->subYear()->toDateString();

        $sales = Sale::forCompany($companyId)
            ->where('status', 'confirmed')
            ->whereDate('sale_date', '>=', $since)
            ->whereDate('sale_date', '<=', $asOf)
            ->whereNotNull('customer_id')
            ->with(['customer:id,name,phone,whatsapp,whatsapp_opt_in', 'items.product:id,name,category_id'])
            ->orderByDesc('sale_date')
            ->get();

        $seen = [];
        foreach ($sales as $sale) {
            if ($sent >= $budget) {
                break;
            }
            $customer = $sale->customer;
            if (! $customer || $customer->whatsapp_opt_in === false) {
                $skipped++;
                continue;
            }
            $phone = $customer->whatsapp ?: $customer->phone;
            if (! $this->validPhone($phone)) {
                $skipped++;
                continue;
            }

            $matchingItems = $sale->items->filter(function ($item) use ($rule) {
                if ($rule->product_id && $rule->product_id !== $item->product_id) {
                    return false;
                }
                if ($rule->category_id && $item->product?->category_id !== $rule->category_id) {
                    return false;
                }

                return $rule->product_id || $rule->category_id;
            });
            if ($matchingItems->isEmpty()) {
                continue;
            }

            $productId = $matchingItems->first()->product_id;
            $dedupe = $sale->customer_id.'|'.$productId.'|'.$season.'|'.$year;
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;

            $already = SeasonalCareSend::query()
                ->where('seasonal_care_rule_id', $rule->id)
                ->where('customer_id', $sale->customer_id)
                ->where('product_id', $productId)
                ->where('season', $season)
                ->where('season_year', $year)
                ->exists();
            if ($already) {
                $skipped++;
                continue;
            }

            $productName = $matchingItems->first()->product_name ?? $matchingItems->first()->product?->name ?? 'your plant';
            $message = str_replace(
                ['{customer_name}', '{product_name}', '{company_name}', '{season}'],
                [$customer->name, $productName, $companyName, $season],
                $rule->message_template,
            );
            $result = $this->whatsapp->sendMessage($phone, $message);
            WhatsAppMessageLog::create([
                'company_id'      => $companyId,
                'recipient_type'  => 'customer',
                'recipient_id'    => $sale->customer_id,
                'recipient_phone' => $phone,
                'message_type'    => 'seasonal_care',
                'message'         => $message,
                'status'          => $result['success'] ? 'sent' : 'failed',
                'error'           => $result['success'] ? null : ($result['message'] ?? null),
                'business_date'   => $asOf,
                'sent_at'         => $result['success'] ? now() : null,
            ]);
            if (! $result['success']) {
                $skipped++;
                continue;
            }

            SeasonalCareSend::create([
                'seasonal_care_rule_id' => $rule->id,
                'customer_id'           => $sale->customer_id,
                'sale_id'               => $sale->id,
                'product_id'            => $productId,
                'season'                => $season,
                'season_year'           => $year,
                'sent_at'               => now(),
            ]);
            $sent++;
        }

        return compact('sent', 'skipped');
    }

    private function validPhone(?string $phone): bool
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        return strlen($digits) >= 10 && strlen($digits) <= 15;
    }
}
