<?php

namespace App\Support\Validation;

/**
 * One email check for the whole app: a proper address like name@shop.com —
 * no spaces, one @, and a domain with a dot (so "ravi@gmail" or "ravi.gmail.com"
 * are refused). Also used for each address in a comma-separated list.
 */
class Email
{
    public const PATTERN = '/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,}$/';

    public const MESSAGE = 'Enter a valid email address, like name@example.com.';

    /** Rules to add after 'required'/'nullable'. */
    public static function rules(): array
    {
        return ['email:rfc', 'regex:'.self::PATTERN];
    }

    public static function valid(?string $value): bool
    {
        return $value !== null && preg_match(self::PATTERN, trim($value)) === 1;
    }

    /** Validation closure for a comma-separated list of addresses. */
    public static function list(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $bad = collect(preg_split('/[,;\s]+/', (string) $value))
                ->map(fn ($e) => trim($e))->filter()
                ->reject(fn ($e) => self::valid($e))->values();
            if ($bad->isNotEmpty()) {
                $fail('Not a valid email address: '.$bad->implode(', ').'. Use addresses like name@example.com, separated by commas.');
            }
        };
    }
}
