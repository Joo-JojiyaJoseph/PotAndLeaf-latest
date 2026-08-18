<?php

namespace App\Support\Barcode;

use App\Models\Product;

/**
 * Generates a stable, company-scoped barcode value for products. The value is
 * Code128-friendly (alphanumeric) and unique per company via a running count,
 * e.g. "PL1-000042". The visual Code128 is rendered client-side for labels.
 */
class BarcodeGenerator
{
    public function forProduct(int|string $companyId): string
    {
        $seq = Product::withTrashed()->where('company_id', $companyId)->count() + 1;

        return sprintf('PL%s-%06d', $companyId, $seq);
    }

    /**
     * Unique barcode for a received batch, derived from the purchase number and
     * the line's position so the same product across two purchases gets two
     * distinct, Code128-friendly barcodes, e.g. "PL1-PO0007-02".
     */
    public function forBatch(int|string $companyId, string $purchaseNo, int $lineNo): string
    {
        $safe = preg_replace('/[^A-Z0-9]/', '', strtoupper($purchaseNo)) ?: 'PUR';

        return sprintf('PL%s-%s-%02d', $companyId, substr($safe, -10), $lineNo);
    }

    /** Unique barcode for a finished-goods batch from a production order. */
    public function forProduction(int|string $companyId, string $orderNo): string
    {
        $safe = preg_replace('/[^A-Z0-9]/', '', strtoupper($orderNo)) ?: 'PRD';

        return sprintf('PL%s-%s', $companyId, substr($safe, -12));
    }

    /** Unique barcode for an individual saleable unit after a bulk split. */
    public function forSplitUnit(int|string $companyId, string $splitNo, int $unitNo): string
    {
        $safe = preg_replace('/[^A-Z0-9]/', '', strtoupper($splitNo)) ?: 'SPLIT';

        return sprintf('PL%s-%s-%04d', $companyId, substr($safe, -8), $unitNo);
    }
}
