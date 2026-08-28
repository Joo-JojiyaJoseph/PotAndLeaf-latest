<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends SMS via Twilio when TWILIO_SMS_FROM is configured; otherwise logs (stub)
 * so local/dev still exercises the rental reminder pipeline.
 */
class SmsService
{
    public function provider(): string
    {
        if (filled(config('whatsapp.twilio.sid')) && filled(config('whatsapp.twilio.token')) && filled(config('whatsapp.twilio.sms_from'))) {
            return 'twilio';
        }

        return 'stub';
    }

    /** @return array{success: bool, message: string, provider: string} */
    public function sendMessage(?string $to, string $message): array
    {
        $number = $this->normalizeNumber($to);
        if (! $number) {
            return ['success' => false, 'message' => 'Customer has no phone number on file.', 'provider' => 'none'];
        }

        $body = mb_substr(preg_replace('/[*_]/', '', $message) ?? $message, 0, 480);

        return match ($this->provider()) {
            'twilio' => $this->sendViaTwilio($number, $body),
            default  => $this->sendViaStub($number, $body),
        };
    }

    private function sendViaTwilio(string $to, string $message): array
    {
        $sid = (string) config('whatsapp.twilio.sid');
        $token = (string) config('whatsapp.twilio.token');
        $from = (string) config('whatsapp.twilio.sms_from');

        try {
            $response = Http::asForm()
                ->withBasicAuth($sid, $token)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => $from,
                    'To'   => $to,
                    'Body' => $message,
                ]);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'SMS sent via Twilio.', 'provider' => 'twilio'];
            }

            Log::warning('Twilio SMS send failed', ['to' => $to, 'status' => $response->status(), 'body' => $response->body()]);

            return ['success' => false, 'message' => 'Twilio could not deliver the SMS.', 'provider' => 'twilio'];
        } catch (Throwable $e) {
            Log::error('Twilio SMS send exception', ['to' => $to, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Could not reach Twilio SMS: '.$e->getMessage(), 'provider' => 'twilio'];
        }
    }

    private function sendViaStub(string $to, string $message): array
    {
        Log::info('SMS message (stub mode — no TWILIO_SMS_FROM configured)', ['to' => $to, 'message' => $message]);

        return [
            'success'  => false,
            'message'  => 'SMS is not configured on this server (set TWILIO_SMS_FROM). Message was logged instead of sent.',
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