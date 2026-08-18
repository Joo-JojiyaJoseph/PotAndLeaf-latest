<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends WhatsApp messages via whichever provider is configured, in priority
 * order: WATI, then Twilio, then a logging "stub" so local/dev environments
 * fail gracefully instead of erroring.
 */
class WhatsAppService
{
    public function provider(): string
    {
        if (filled(config('whatsapp.wati.api_url')) && filled(config('whatsapp.wati.api_token'))) {
            return 'wati';
        }

        if (filled(config('whatsapp.twilio.sid')) && filled(config('whatsapp.twilio.token')) && filled(config('whatsapp.twilio.whatsapp_from'))) {
            return 'twilio';
        }

        return 'stub';
    }

    /**
     * @return array{success: bool, message: string, provider: string}
     */
    public function sendMessage(?string $to, string $message): array
    {
        $number = $this->normalizeNumber($to);
        if (! $number) {
            return ['success' => false, 'message' => 'Customer has no WhatsApp/phone number on file.', 'provider' => 'none'];
        }

        return match ($this->provider()) {
            'wati'   => $this->sendViaWati($number, $message),
            'twilio' => $this->sendViaTwilio($number, $message),
            default  => $this->sendViaStub($number, $message),
        };
    }

    private function sendViaWati(string $to, string $message): array
    {
        $baseUrl = rtrim((string) config('whatsapp.wati.api_url'), '/');
        $phone = ltrim($to, '+');

        try {
            $response = Http::withToken(config('whatsapp.wati.api_token'))
                ->asForm()
                ->post("{$baseUrl}/api/v1/sendSessionMessage/{$phone}", [
                    'messageText' => $message,
                ]);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'WhatsApp message sent via WATI.', 'provider' => 'wati'];
            }

            Log::warning('WATI WhatsApp send failed', ['to' => $to, 'status' => $response->status(), 'body' => $response->body()]);

            return ['success' => false, 'message' => 'WATI could not deliver the message. Check the number and template settings.', 'provider' => 'wati'];
        } catch (Throwable $e) {
            Log::error('WATI WhatsApp send exception', ['to' => $to, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Could not reach WATI: '.$e->getMessage(), 'provider' => 'wati'];
        }
    }

    private function sendViaTwilio(string $to, string $message): array
    {
        $sid = (string) config('whatsapp.twilio.sid');
        $token = (string) config('whatsapp.twilio.token');
        $from = (string) config('whatsapp.twilio.whatsapp_from');

        try {
            $response = Http::asForm()
                ->withBasicAuth($sid, $token)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => "whatsapp:{$from}",
                    'To'   => "whatsapp:{$to}",
                    'Body' => $message,
                ]);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'WhatsApp message sent via Twilio.', 'provider' => 'twilio'];
            }

            Log::warning('Twilio WhatsApp send failed', ['to' => $to, 'status' => $response->status(), 'body' => $response->body()]);

            return ['success' => false, 'message' => 'Twilio could not deliver the message. Check the number and sandbox/opt-in status.', 'provider' => 'twilio'];
        } catch (Throwable $e) {
            Log::error('Twilio WhatsApp send exception', ['to' => $to, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Could not reach Twilio: '.$e->getMessage(), 'provider' => 'twilio'];
        }
    }

    /** No provider configured — log so the message is at least visible in local/dev. */
    private function sendViaStub(string $to, string $message): array
    {
        Log::info('WhatsApp message (stub mode — no provider configured)', ['to' => $to, 'message' => $message]);

        return [
            'success'  => false,
            'message'  => 'WhatsApp is not configured on this server (no WATI or Twilio credentials). Message was logged instead of sent.',
            'provider' => 'stub',
        ];
    }

    private function normalizeNumber(?string $number): ?string
    {
        if (! $number) {
            return null;
        }

        $digits = preg_replace('/[^\d+]/', '', $number);
        if (! $digits) {
            return null;
        }

        if (! str_starts_with($digits, '+')) {
            $digits = strlen($digits) === 10 ? "+91{$digits}" : "+{$digits}";
        }

        return $digits;
    }
}
