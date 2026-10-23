<?php

namespace App\Services;

use App\Models\AdvanceOrder;
use App\Models\Backorder;
use App\Models\Company;
use App\Models\Customer;
use App\Models\WhatsAppMessageLog;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp messages to customers about their backorders and advance orders
 * (proposal module 10: "customer notification").
 *
 *   booked  – sent automatically when the order is created
 *   ready   – sent by staff from the order screen when the stock is in
 *
 * Every attempt is written to whatsapp_message_logs. Sending never throws:
 * an order is still saved if WhatsApp is off or the customer has no number.
 */
class CustomerOrderNotificationService
{
    public function __construct(
        private readonly WhatsAppService $whatsapp,
        private readonly SettingsService $settings,
    ) {}

    /** @return array{success: bool, message: string, provider?: string} */
    public function notifyBackorder(Backorder $order, string $event = 'ready'): array
    {
        $order->loadMissing(['items', 'customer:id,name,phone,whatsapp', 'company:id,name']);

        $lines = $order->items
            ->filter(fn ($i) => $i->pendingQty() > 0 || $event === 'booked')
            ->map(fn ($i) => '- '.$i->product_name.': '.$this->qty($event === 'booked' ? (float) $i->ordered_qty : $i->pendingQty()))
            ->values()
            ->all();

        $intro = $event === 'booked'
            ? "We have noted your order *{$order->order_no}*. These items are not in stock right now; we will message you as soon as they arrive:"
            : "Good news! The items on your order *{$order->order_no}* are now in stock. Please visit us to collect them:";

        return $this->send($order->company, $order->customer, 'backorder_'.$event, $intro, $lines);
    }

    /** @return array{success: bool, message: string, provider?: string} */
    public function notifyAdvanceOrder(AdvanceOrder $order, string $event = 'ready'): array
    {
        $order->loadMissing(['items', 'customer:id,name,phone,whatsapp', 'company:id,name']);

        $money = fn ($n) => '₹'.number_format((float) $n, 2);
        $lines = $order->items->map(fn ($i) => '- '.$i->product_name.': '.$this->qty((float) $i->qty))->values()->all();
        $lines[] = '';
        $lines[] = 'Order total: '.$money($order->grand_total);
        if ((float) $order->advance_amount > 0) {
            $lines[] = 'Advance received: '.$money($order->advance_amount);
            $lines[] = 'Balance to pay: '.$money(max(0, (float) $order->grand_total - (float) $order->advance_amount));
        }

        $intro = $event === 'booked'
            ? "Thank you for your booking *{$order->order_no}*"
                .($order->expected_date ? ' (expected '.$order->expected_date->format('d M Y').')' : '').':'
            : "Your booking *{$order->order_no}* is ready. Please visit us to collect it:";

        return $this->send($order->company, $order->customer, 'advance_order_'.$event, $intro, $lines);
    }

    /** @param list<string> $lines */
    private function send(?Company $company, ?Customer $customer, string $type, string $intro, array $lines): array
    {
        if (! $company) {
            return ['success' => false, 'message' => 'Order is missing company details.'];
        }
        if ($this->settings->get($company->id, 'whatsapp_enabled') !== '1') {
            return ['success' => false, 'message' => 'WhatsApp is turned off for this company (Settings).'];
        }

        $name = $customer?->name ? "Dear {$customer->name},\n" : '';
        $message = $name.$intro."\n".implode("\n", $lines)."\n\n— {$company->name}";
        $phone = $customer?->whatsapp ?: $customer?->phone;

        try {
            $result = $this->whatsapp->sendMessage($phone, $message);
        } catch (\Throwable $e) {
            Log::warning('Customer order WhatsApp failed', ['type' => $type, 'error' => $e->getMessage()]);
            $result = ['success' => false, 'message' => 'Could not send WhatsApp: '.$e->getMessage()];
        }

        try {
            WhatsAppMessageLog::create([
                'company_id'      => $company->id,
                'recipient_type'  => 'customer',
                'recipient_id'    => $customer?->id,
                'recipient_phone' => $phone,
                'message_type'    => $type,
                'message'         => $message,
                'status'          => $result['success'] ? 'sent' : 'failed',
                'error'           => $result['success'] ? null : ($result['message'] ?? null),
                'business_date'   => now()->toDateString(),
                'sent_at'         => $result['success'] ? now() : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not log customer order WhatsApp', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    private function qty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    }
}
