<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\SeasonalCareRule;
use App\Models\SeasonalCareSend;
use App\Models\WhatsAppMessageLog;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Carbon;

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
        $sent = 0;
        $skipped = 0;

        $rules = SeasonalCareRule::forCompany($companyId)->where('is_active', true)->get();

        foreach ($rules as $rule) {
            if ($rule->season_months && ! in_array($month, $rule->season_months, true)) {
                continue;
            }

            $triggerDate = Carbon::parse($asOf)->subDays($rule->days_after_purchase)->toDateString();

            $sales = Sale::forCompany($companyId)
                ->where('status', 'confirmed')
                ->whereDate('sale_date', $triggerDate)
                ->with(['customer:id,name,phone,whatsapp', 'items.product:id,name,category_id'])
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

                $customer = $sale->customer;
                $phone = $customer?->whatsapp ?: $customer?->phone;
                if (! $phone) {
                    $skipped++;
                    continue;
                }

                $productName = $matchingItems->first()->product_name ?? $matchingItems->first()->product?->name ?? 'your plant';
                $message = str_replace(
                    ['{customer_name}', '{product_name}', '{company_name}'],
                    [$customer->name, $productName, ''],
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
                        'sent_at'               => now(),
                    ]);
                    $sent++;
                } else {
                    $skipped++;
                }
            }
        }

        return compact('sent', 'skipped');
    }
}
