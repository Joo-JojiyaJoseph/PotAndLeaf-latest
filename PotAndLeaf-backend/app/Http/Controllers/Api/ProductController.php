<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Services\ProductService;
use App\Support\Api\ApiResponse;
use App\Support\Api\AssertsRecordCompany;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse, AssertsRecordCompany, ResolvesFilterCompany;

    public function __construct(private readonly ProductService $products) {}

    public function index(Request $request): JsonResponse
    {
        $this->allow($request, 'products.view');

        $products = $this->applyListCompanyScope(Product::query(), $request)
            ->with(['unit:id,short_name,name', 'category:id,name'])
            ->when(filled($request->query('search')), fn ($q) => $q->search($request->query('search')))
            ->when(filled($request->query('category_id')), fn ($q) => $q->where('category_id', $request->query('category_id')))
            ->when(filled($request->query('status')), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->boolean('low_only'), fn ($q) => $q->where('reorder_level', '>', 0)->whereColumn('current_stock', '<=', 'reorder_level'))
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 20), 100))
            ->withQueryString();

        return $this->ok(ProductResource::collection($products));
    }

    /** Lookups the product form needs: categories, brands, units, tax rates. */
    public function formData(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'products.view');

        $map = fn ($rows) => $rows->map(fn ($r) => ['id' => $r->id, 'name' => $r->name] + (isset($r->short_name) ? ['short_name' => $r->short_name] : []) + (isset($r->parent_id) ? ['parent_id' => $r->parent_id] : []));

        $categories = ProductCategory::query()
            ->where('company_id', $company->id)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        return $this->ok([
            'categories' => $map($categories),
            'units'      => ProductUnit::query()
                ->where('company_id', $company->id)
                ->orderBy('name')
                ->get(['id', 'name', 'short_name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'short_name' => $u->short_name]),
            'suppliers'  => Supplier::forCompany($company->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]),
            'tax_rates'  => [0, 5, 12, 18, 28],
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $product = $this->products->create($company->id, $request->validated());

        return $this->created(new ProductResource($product->load(['category', 'brand', 'unit'])), 'Product created.');
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->allow($request, 'products.view');
        $this->assertRecordCompany($request, $product);

        return $this->ok(new ProductResource($product->load(['category', 'brand', 'unit', 'suppliers'])));
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->assertRecordCompany($request, $product, writable: true);
        $updated = $this->products->update($product, $request->validated());

        return $this->ok(new ProductResource($updated->load(['category', 'brand', 'unit'])), 'Product updated.');
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->allow($request, 'products.delete');
        $this->assertRecordCompany($request, $product, writable: true);
        $this->products->delete($product);

        return $this->message('Product deleted.');
    }

    /** Every product with its active batches + barcodes, for the inventory view. */
    public function batchesOverview(Request $request): JsonResponse
    {
        $this->allow($request, 'products.view');
        $companyId = $this->company($request)->id;

        $batches = \App\Models\ProductBatch::forCompany($companyId)
            ->where('remaining_qty', '>', 0)
            ->with(['product:id,sku,name', 'purchase:id,purchase_no', 'bulkSplit:id,split_no,source_product_name'])
            ->orderBy('created_at')
            ->get()
            ->map(function ($b) {
                try {
                    $source = $b->purchase?->purchase_no
                        ?? ($b->bulkSplit ? "Split {$b->bulkSplit->split_no}" : null)
                        ?? ($b->production_order_id ? 'Production' : ($b->purchase_id ? 'Purchase' : 'Opening'));

                    return [
                        'id'            => $b->id,
                        'product_id'    => $b->product_id,
                        'sku'           => $b->product?->sku ?? '—',
                        'product'       => $b->product?->name ?? 'Unknown product',
                        'batch_no'      => $b->batch_no ?? '—',
                        'barcode'       => $b->barcode,
                        'remaining_qty' => (float) ($b->remaining_qty ?? 0),
                        'qty'           => (float) ($b->qty ?? 0),
                        'cost_price'    => (float) ($b->cost_price ?? 0),
                        'source'        => $source,
                        'bulk_split_id' => $b->bulk_split_id,
                        'source_product' => $b->bulkSplit?->source_product_name,
                    ];
                } catch (\Throwable) {
                    return [
                        'id'            => $b->id,
                        'product_id'    => $b->product_id,
                        'sku'           => '—',
                        'product'       => 'Unknown product',
                        'batch_no'      => $b->batch_no ?? '—',
                        'barcode'       => $b->barcode,
                        'remaining_qty' => (float) ($b->remaining_qty ?? 0),
                        'qty'           => (float) ($b->qty ?? 0),
                        'source'        => 'Unknown',
                    ];
                }
            })
            ->filter(fn ($row) => filled($row['id']))
            ->values();

        // Products holding stock that isn't covered by any batch yet.
        $covered = $batches->groupBy('product_id')->map(fn ($g) => $g->sum('remaining_qty'));
        $untracked = Product::forCompany($companyId)
            ->where('current_stock', '>', 0)
            ->get(['id', 'sku', 'name', 'current_stock'])
            ->map(fn ($p) => [
                'product_id'    => $p->id,
                'sku'           => $p->sku ?? '—',
                'product'       => $p->name ?? 'Unknown product',
                'untracked_qty' => round((float) $p->current_stock - (float) ($covered[$p->id] ?? 0), 3),
            ])
            ->filter(fn ($r) => $r['untracked_qty'] > 0.001)
            ->values();

        return $this->ok(['batches' => $batches, 'untracked' => $untracked]);
    }

    /** One-time: mint an "opening" barcoded batch for stock that predates batches. */
    public function generateOpeningBatches(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('products.update', $this->company($request)->id), 403);
        $companyId = $this->company($request)->id;

        $created = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($companyId, &$created) {
            $products = Product::forCompany($companyId)->where('current_stock', '>', 0)->lockForUpdate()->get();
            foreach ($products as $p) {
                $covered = (float) \App\Models\ProductBatch::forCompany($companyId)
                    ->where('product_id', $p->id)->where('remaining_qty', '>', 0)->sum('remaining_qty');
                $gap = round((float) $p->current_stock - $covered, 3);
                if ($gap <= 0.001) {
                    continue;
                }
                \App\Models\ProductBatch::create([
                    'company_id'    => $companyId,
                    'product_id'    => $p->id,
                    'batch_no'      => 'OPEN-'.$p->sku,
                    'barcode'       => 'PLO'.$companyId.'-'.strtoupper(substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 12)),
                    'qty'           => $gap,
                    'remaining_qty' => $gap,
                    'cost_price'    => (float) $p->cost_price,
                    'status'        => 'active',
                    'received_at'   => now(),
                ]);
                $created++;
            }
        });

        return $this->ok(['created' => $created], "Opening barcodes generated for {$created} product(s).");
    }

    /** Resolve a scanned barcode to its batch (with stock left) for POS/damage/return. */
    public function scanBatch(Request $request): JsonResponse
    {
        $this->allow($request, 'products.view');
        $companyId = $this->company($request)->id;
        $barcode = trim((string) $request->query('barcode'));

        abort_if($barcode === '', 422, 'Provide a barcode.');

        $batch = \App\Models\ProductBatch::forCompany($companyId)
            ->where('barcode', $barcode)
            ->with('product:id,sku,name,mrp,retail_price,gst_rate,hsn_code')
            ->first();

        if ($batch) {
            abort_if((float) $batch->remaining_qty <= 0, 422, 'This batch is out of stock.');

            return $this->ok([
                'batch_id'      => $batch->id,
                'batch_no'      => $batch->batch_no,
                'barcode'       => $batch->barcode,
                'remaining_qty' => (float) $batch->remaining_qty,
                'product'       => [
                    'id'       => $batch->product?->id,
                    'sku'      => $batch->product?->sku,
                    'name'     => $batch->product?->name,
                    'hsn_code' => $batch->product?->hsn_code,
                    'gst_rate' => (float) $batch->product?->gst_rate,
                    'price'    => (float) ($batch->product?->retail_price ?: $batch->product?->mrp),
                ],
            ]);
        }

        $splitUnit = \App\Models\BulkSplitUnit::query()
            ->where('barcode', $barcode)
            ->whereHas('split', fn ($q) => $q->where('company_id', $companyId)->where('status', 'confirmed'))
            ->with(['product:id,sku,name,mrp,retail_price,gst_rate,hsn_code', 'item'])
            ->first();

        if ($splitUnit?->product) {
            $product = $splitUnit->product;
            $batch = \App\Models\ProductBatch::forCompany($companyId)
                ->where('product_id', $product->id)
                ->where('remaining_qty', '>', 0)
                ->orderByDesc('received_at')
                ->first();

            abort_if(! $batch && (float) $product->current_stock <= 0, 422, 'This split unit is out of stock.');

            return $this->ok([
                'batch_id'      => $batch?->id,
                'batch_no'      => $batch?->batch_no,
                'barcode'       => $splitUnit->barcode,
                'remaining_qty' => (float) ($batch?->remaining_qty ?? $product->current_stock),
                'product'       => [
                    'id'       => $product->id,
                    'sku'      => $product->sku,
                    'name'     => $product->name,
                    'hsn_code' => $product->hsn_code,
                    'gst_rate' => (float) $product->gst_rate,
                    'price'    => (float) ($product->retail_price ?: $product->mrp),
                ],
            ]);
        }

        $product = \App\Models\Product::forCompany($companyId)
            ->where('barcode', $barcode)
            ->where('status', 'active')
            ->first(['id', 'sku', 'name', 'mrp', 'retail_price', 'gst_rate', 'hsn_code', 'barcode', 'current_stock']);

        abort_unless($product, 404, 'No product or batch found for this barcode.');
        abort_if((float) $product->current_stock <= 0, 422, 'This product is out of stock.');

        return $this->ok([
            'batch_id'      => null,
            'batch_no'      => null,
            'barcode'       => $product->barcode,
            'remaining_qty' => (float) $product->current_stock,
            'product'       => [
                'id'       => $product->id,
                'sku'      => $product->sku,
                'name'     => $product->name,
                'hsn_code' => $product->hsn_code,
                'gst_rate' => (float) $product->gst_rate,
                'price'    => (float) ($product->retail_price ?: $product->mrp),
            ],
        ]);
    }

    /** Batches (received lots + their barcodes) for this product. */
    public function batches(Request $request, Product $product): JsonResponse
    {
        $this->allow($request, 'products.view');
        $this->assertRecordCompany($request, $product);

        $batches = \App\Models\ProductBatch::forCompany($product->company_id)
            ->where('product_id', $product->id)
            ->with(['supplier:id,name', 'purchase:id,purchase_no'])
            ->orderByDesc('received_at')
            ->get()
            ->each(fn ($b) => $b->setRelation('product', $product));

        return $this->ok(\App\Http\Resources\ProductBatchResource::collection($batches));
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    public function toggleStatus(Request $request, Product $product): JsonResponse
    {
        $company = $request->attributes->get('company');
        abort_unless($request->user()->hasPermission('products.update', $company->id), 403);
        $this->assertRecordCompany($request, $product, writable: true);

        $data = $request->validate(['status' => ['required', 'in:active,inactive']]);
        $product->update(['status' => $data['status']]);

        return $this->ok(['id' => $product->id, 'status' => $product->status], 'Status updated.');
    }
}
