<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\StockLedgerResource;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\ReportExportService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ReportExportService $export,
    ) {}

    public function stock(Request $request): JsonResponse
    {
        $company = $this->allow($request);

        $filters = [
            'search'   => $request->query('search'),
            'low_only' => $request->boolean('low_only'),
            'per_page' => $request->query('per_page'),
        ];

        $levels = $this->inventory->stockLevels($company->id, $filters);
        $levels->loadMissing('unit:id,short_name,name');

        return $this->ok(ProductResource::collection($levels));
    }

    public function alerts(Request $request): JsonResponse
    {
        $company = $this->allow($request);

        return $this->ok(
            $this->inventory->reorderAlerts($company->id)->map(fn ($p) => [
                'id'            => $p->id,
                'sku'           => $p->sku,
                'name'          => $p->name,
                'current_stock' => (float) $p->current_stock,
                'reorder_level' => (float) $p->reorder_level,
            ])
        );
    }

    public function ledgerFormData(Request $request): JsonResponse
    {
        $company = $this->allow($request);

        $products = Product::forCompany($company->id)
            ->orderBy('name')
            ->get(['id', 'sku', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name]);

        return $this->ok([
            'products'         => $products,
            'reference_types'  => $this->referenceTypeOptions(),
        ]);
    }

    public function ledger(Request $request): JsonResponse
    {
        $company = $this->allow($request);
        $filters = $this->ledgerFilters($request);

        return $this->ok(
            StockLedgerResource::collection(
                $this->inventory->ledgerFor($company->id, $filters)
            )
        );
    }

    public function exportLedger(Request $request)
    {
        $company = $this->allow($request);
        $filters = $this->ledgerFilters($request);

        $rows = $this->inventory->ledgerExportRows($company->id, $filters)->map(fn ($e) => [
            'occurred_at'    => optional($e->occurred_at)->toDateTimeString(),
            'sku'            => $e->product?->sku ?? '',
            'product_name'   => $e->product?->name ?? '',
            'direction'      => $e->direction === 'in' ? 'In' : 'Out',
            'qty'            => (float) $e->qty,
            'unit_cost'      => $e->unit_cost !== null ? (float) $e->unit_cost : '',
            'balance_after'  => (float) $e->balance_after,
            'reference_type' => $this->referenceLabel($e->reference_type),
            'note'           => $e->note ?? '',
        ]);

        $headers = ['occurred_at', 'sku', 'product_name', 'direction', 'qty', 'unit_cost', 'balance_after', 'reference_type', 'note'];
        $labels = [
            'occurred_at'    => 'Date & time',
            'sku'            => 'SKU',
            'product_name'   => 'Product',
            'direction'      => 'Direction',
            'qty'            => 'Qty',
            'unit_cost'      => 'Unit cost',
            'balance_after'  => 'Balance after',
            'reference_type' => 'Source',
            'note'           => 'Note',
        ];

        $from = $filters['from'] ?? 'all';
        $to = $filters['to'] ?? 'all';

        return $this->export->excelCsv("stock-ledger-{$from}-{$to}.csv", $rows, $headers, $labels);
    }

    public function valuation(Request $request): JsonResponse
    {
        $company = $this->allow($request);

        return $this->ok($this->inventory->valuation($company->id));
    }

    public function movement(Request $request): JsonResponse
    {
        $company = $this->allow($request);
        $days = max(1, min((int) $request->query('days', 30), 365));

        return $this->ok($this->inventory->movement($company->id, $days));
    }

    public function byLocation(Request $request, \App\Services\LocationStockService $locations): JsonResponse
    {
        $company = $this->allow($request);
        $locationId = $request->query('location_id') ?: null;

        return $this->ok(['balances' => $locations->balances($company->id, $locationId)]);
    }

    /** @return array<string,mixed> */
    private function ledgerFilters(Request $request): array
    {
        $request->validate([
            'product_id'     => ['nullable', 'uuid'],
            'reference_type' => ['nullable', 'string', 'max:40'],
            'direction'      => ['nullable', 'in:in,out'],
            'from'           => ['nullable', 'date'],
            'to'             => ['nullable', 'date', 'after_or_equal:from'],
            'search'         => ['nullable', 'string', 'max:100'],
            'per_page'       => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        return $request->only(['product_id', 'reference_type', 'direction', 'from', 'to', 'search', 'per_page']);
    }

    /** @return array<int, array{value: string, label: string}> */
    private function referenceTypeOptions(): array
    {
        return collect([
            'purchase', 'purchase-cancel', 'sale', 'sale-cancel',
            'production', 'production-cancel', 'bulk-split', 'bulk-split-cancel',
            'transfer', 'purchase-return', 'purchase-return-cancel',
            'sales-return', 'sales-return-cancel', 'stock-verification',
            'rental', 'rental-return', 'rental-cancel',
        ])->map(fn ($v) => ['value' => $v, 'label' => $this->referenceLabel($v)])->values()->all();
    }

    private function referenceLabel(?string $type): string
    {
        return match ($type) {
            'purchase'              => 'Purchase',
            'purchase-cancel'       => 'Purchase (reversal)',
            'sale'                  => 'Sale',
            'sale-cancel'           => 'Sale (reversal)',
            'production'            => 'Production',
            'production-cancel'     => 'Production (reversal)',
            'bulk-split'            => 'Bulk split',
            'bulk-split-cancel'     => 'Bulk split (reversal)',
            'transfer'              => 'Stock transfer',
            'purchase-return'       => 'Purchase return',
            'purchase-return-cancel'=> 'Purchase return (reversal)',
            'sales-return'          => 'Sales return',
            'sales-return-cancel'   => 'Sales return (reversal)',
            'stock-verification'    => 'Stock verification',
            'rental'                => 'Rental',
            'rental-return'         => 'Rental return',
            'rental-cancel'           => 'Rental (reversal)',
            default                 => $type ? ucfirst(str_replace('-', ' ', $type)) : '—',
        };
    }

    private function allow(Request $request)
    {
        $company = $this->filterCompany($request);
        abort_unless($request->user()->hasPermission('inventory.view', $company->id), 403);

        return $company;
    }
}
