<?php

namespace App\Support\Validation;

use Closure;

/**
 * Format checks for Indian business numbers, shared by every form.
 * Inputs are upper-cased and stripped of spaces before these run.
 */
final class IndianFormats
{
    /** 15 characters: 2-digit state code, PAN (10), entity no., Z, check char. e.g. 32ABCDE1234F1Z5 */
    public const GSTIN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    /** e.g. ABCDE1234F */
    public const PAN = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    /** 6 digits, cannot start with 0. e.g. 682001 */
    public const PINCODE = '/^[1-9][0-9]{5}$/';

    /** 2 digits, e.g. 32 for Kerala. */
    public const STATE_CODE = '/^[0-9]{2}$/';

    public const MESSAGES = [
        'gst_number.regex' => 'Enter a valid 15-character GST number, e.g. 32ABCDE1234F1Z5.',
        'pan_number.regex' => 'Enter a valid PAN, e.g. ABCDE1234F.',
        'pincode.regex'    => 'Enter a valid 6-digit PIN code, e.g. 682001.',
        'state_code.regex' => 'State code must be 2 digits, e.g. 32 for Kerala.',
    ];

    /** Upper-case and remove spaces from GST / PAN / IFSC; trim PIN code. */
    public static function normalise(array $input): array
    {
        $out = [];
        foreach (['gst_number', 'pan_number', 'bank_ifsc'] as $k) {
            if (isset($input[$k]) && is_string($input[$k])) {
                $out[$k] = strtoupper(preg_replace('/\s+/', '', $input[$k]));
            }
        }
        if (isset($input['pincode']) && is_string($input['pincode'])) {
            $out['pincode'] = preg_replace('/\s+/', '', $input['pincode']);
        }

        return $out;
    }

    /** The first 2 digits of a GST number are the state code; they must agree when both are given. */
    public static function gstMatchesStateCode(?string $stateCode): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($stateCode) {
            if (is_string($value) && strlen($value) >= 2 && $stateCode !== null && $stateCode !== ''
                && preg_match(self::STATE_CODE, $stateCode) && substr($value, 0, 2) !== $stateCode) {
                $fail("This GST number is for state code ".substr($value, 0, 2).", but the state code is {$stateCode}. Check both.");
            }
        };
    }

    /**
     * Duplicate check for a field stored encrypted (the database cannot compare
     * encrypted text, so each saved value is decrypted and compared here).
     */
    public static function notDuplicateEncrypted(string $modelClass, int|string $companyId, string $field, int|string|null $ignoreId, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($modelClass, $companyId, $field, $ignoreId, $message) {
            if (! is_string($value) || $value === '') {
                return;
            }
            $taken = $modelClass::query()->where('company_id', $companyId)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->whereNotNull($field)->get(['id', $field])
                ->contains(fn ($m) => strtoupper(preg_replace('/\s+/', '', (string) $m->{$field})) === $value);
            if ($taken) {
                $fail($message);
            }
        };
    }
}
