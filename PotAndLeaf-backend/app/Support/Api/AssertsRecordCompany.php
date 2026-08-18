<?php

namespace App\Support\Api;

use Illuminate\Http\Request;

/**
 * Cross-company read access for super-admins on show/update routes.
 * List/read aggregations use ResolvesFilterCompany; this trait guards
 * individual records loaded by route model binding.
 *
 * Writes always require X-Company-Id to match the record's company column.
 */
trait AssertsRecordCompany
{
    protected function assertRecordCompany(
        Request $request,
        object $record,
        bool $writable = false,
        string $companyColumn = 'company_id',
        ?string $writableMessage = null,
    ): void {
        $headerId = (string) $request->attributes->get('company')->id;
        $recordId = (string) data_get($record, $companyColumn);

        if ($recordId === $headerId) {
            return;
        }

        if ($writable) {
            abort(404, $writableMessage ?? 'Switch to the record company to modify this item.');
        }

        if (filled($request->query('company_id')) && $recordId === (string) $request->query('company_id')) {
            return;
        }

        if ($request->user()->is_super_admin) {
            return;
        }

        abort(404);
    }
}
