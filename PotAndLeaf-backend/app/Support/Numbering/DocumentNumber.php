<?php

namespace App\Support\Numbering;

use Illuminate\Database\Eloquent\Builder;

/**
 * Next document number, e.g. INV-000007.
 *
 * Uses the highest number already used (not a row count), so a number is
 * never given twice even after a record has been deleted.
 */
final class DocumentNumber
{
    public static function next(Builder $query, string $column, string $prefix, int $pad = 6): string
    {
        $pattern = '/^'.preg_quote($prefix, '/').'(\d+)$/';
        $max = 0;
        foreach ((clone $query)->where($column, 'like', $prefix.'%')->pluck($column) as $value) {
            if (preg_match($pattern, (string) $value, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $prefix.str_pad((string) ($max + 1), $pad, '0', STR_PAD_LEFT);
    }
}
