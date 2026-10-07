<?php

namespace App\Contracts;

/**
 * Provider-agnostic WhatsApp send. Adapters live in App\Services\WhatsApp.
 */
interface WhatsAppSender
{
    public function provider(): string;

    /**
     * @return array{success: bool, message: string, provider: string}
     */
    public function sendMessage(?string $to, string $message): array;
}
