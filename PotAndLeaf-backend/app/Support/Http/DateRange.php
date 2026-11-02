<?php

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * From / To dates of a report or book.
 *  - Not sent at all      → the screen's usual default (e.g. last 30 days).
 *  - Sent but left empty  → no limit: From = the very start, To = today.
 * So clearing a date box on screen shows everything instead of a hidden default.
 */
class DateRange
{
    public const EARLIEST = '2000-01-01';

    public static function from(Request $request, string $default, string $key = 'from'): string
    {
        $value = $request->query($key);
        if (filled($value)) {
            return (string) $value;
        }

        return $request->query->has($key) ? self::EARLIEST : $default;
    }

    public static function to(Request $request, string $default, string $key = 'to'): string
    {
        $value = $request->query($key);
        if (filled($value)) {
            return (string) $value;
        }

        return $request->query->has($key) ? now()->toDateString() : $default;
    }
}
