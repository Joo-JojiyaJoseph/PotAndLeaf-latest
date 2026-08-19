<?php

namespace App\Support\WhatsApp;

class TemplateRenderer
{
    /** Replace {key} placeholders in a template body. */
    public static function render(string $template, array $vars): string
    {
        $out = $template;
        foreach ($vars as $key => $value) {
            $out = str_replace('{'.$key.'}', (string) $value, $out);
        }

        return $out;
    }
}
