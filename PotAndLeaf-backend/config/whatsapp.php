<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Business Providers
    |--------------------------------------------------------------------------
    |
    | WhatsAppService picks a provider at send time: WATI first (if both its
    | keys are set), then Twilio (if all three of its keys are set), else it
    | falls back to a "stub" mode that logs the message and reports failure —
    | useful for local/dev so the UI can still show a graceful error.
    |
    */

    'wati' => [
        'api_url'   => env('WATI_API_URL'),
        'api_token' => env('WATI_API_TOKEN'),
    ],

    'twilio' => [
        'sid'           => env('TWILIO_SID'),
        'token'         => env('TWILIO_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Message templates
    |--------------------------------------------------------------------------
    |
    | Template/session-message names as configured on the provider side, in
    | case a provider requires an approved template name instead of free text.
    |
    */

    'templates' => [
        'rental_invoice' => env('WHATSAPP_TEMPLATE_RENTAL_INVOICE', 'rental_invoice'),
    ],

];
