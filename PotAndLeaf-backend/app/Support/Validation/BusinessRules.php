<?php

namespace App\Support\Validation;

use Closure;
use Illuminate\Support\Carbon;

/** Small checks shared by bill forms (sales, purchases, returns, money). */
final class BusinessRules
{
    /** Today in India. The server clock is UTC, which is behind India until 5:30 am. */
    public static function today(): string
    {
        return Carbon::now('Asia/Kolkata')->toDateString();
    }

    /** A bill date cannot be in the future. */
    public static function notFuture(string $what = 'Date'): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($what) {
            try {
                $d = Carbon::parse((string) $value)->toDateString();
            } catch (\Throwable) {
                return; // the 'date' rule reports bad dates
            }
            if ($d > self::today()) {
                $fail("{$what} cannot be in the future.");
            }
        };
    }

    /** A line's ₹ discount cannot be more than qty × rate of the same line. */
    public static function discountNotAboveLine(callable $input): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($input) {
            if (! preg_match('/^items\.(\d+)\.discount$/', $attribute, $m) || ! is_numeric($value)) {
                return;
            }
            $qty = (float) ($input("items.{$m[1]}.qty") ?? 0);
            $rate = (float) ($input("items.{$m[1]}.rate") ?? 0);
            if ((float) $value > round($qty * $rate, 2) + 0.001) {
                $fail('Discount (₹'.$value.') cannot be more than the line value (₹'.number_format($qty * $rate, 2, '.', '').').');
            }
        };
    }
}
