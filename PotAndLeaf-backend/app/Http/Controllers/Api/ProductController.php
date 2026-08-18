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
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(private readonly ProductService $products) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'products.view');

        $products = Product::query()
            ->forCompany($company->id)
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
        $this->sameCompany($request, $product);

        return $this->ok(new ProductResource($product->load(['category', 'brand', 'unit', 'suppliers'])));
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->sameCompany($request, $product);
        $updated = $this->products->update($product, $request->validated());

        return $this->ok(new ProductResource($updated->load(['category', 'brand', 'unit'])), 'Product updated.');
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->allow($request, 'products.delete');
        $this->sameCompany($request, $product);
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
            ->with(['product:id,sku,name', 'purchase:id,purchase_no'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($b) => [
                'id'            => $b->id,
                'product_id'    => $b->product_id,
                'sku'           => $b->product?->sku,
                'product'       => $b->product?->name,
                'batch_no'      => $b->batch_no,
                'barcode'       => $b->barcode,
                'remaining_qty' => (float) $b->remaining_qty,
                'qty'           => (float) $b->qty,
                'source'        => $b->purchase?->purchase_no
                    ?? ($b->production_order_id ? 'Production' : ($b->purchase_id ? 'Purchase' : 'Opening')),
            ])->values();

        // Products holding stock that isn't covered by any batch yet.
        $covered = $batches->groupBy('product_id')->map(fn ($g) => $g->sum('remaining_qty'));
        $untracked = Product::forCompany($companyId)
            ->where('current_stock', '>', 0)
            ->get(['id', 'sku', 'name', 'current_stock'])
            ->map(fn ($p) => [
                'product_id' => $p->id, 'sku' => $p->sku, 'product' => $p->name,
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

        abort_unless($batch, 404, 'No batch found for this barcode.');
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

    /** Batches (received lots + their barcodes) for this product. */
    public function batches(Request $request, Product $product): JsonResponse
    {
        $this->allow($request, 'products.view');
        $this->sameCompany($request, $product);

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

    private function sameCompany(Request $request, Product $product): void
    {
        abort_unless((string) $product->company_id === (string) $this->company($request)->id, 404);
    }

    public function toggleStatus(Request $request, Product $product): JsonResponse
    {
        $company = $request->attributes->get('company');
        abort_unless($request->user()->hasPermission('products.update', $company->id), 403);
        abort_unless((string) $product->company_id === (string) $company->id, 404);

        $data = $request->validate(['status' => ['required', 'in:active,inactive']]);
        $product->update(['status' => $data['status']]);

        return $this->ok(['id' => $product->id, 'status' => $product->status], 'Status updated.');
    }
}
