<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseRequest;
use App\Http\Requests\Purchase\UpdatePurchaseRequest;
use App\Http\Resources\PurchaseResource;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PurchaseService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(private readonly PurchaseService $purchases) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->filterCompany($request);
        abort_unless($request->user()->hasPermission('purchases.view', $company->id), 403);

        $filters = $request->only(['search', 'status', 'supplier_id', 'per_page']);

        return $this->ok(PurchaseResource::collection($this->purchases->list($company->id, $filters)));
    }

    /** Options the purchase entry form needs: suppliers, products, tax rates. */
    public function formData(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'purchases.create');

        $suppliers = Supplier::query()->forCompany($company->id)
            ->orderBy('name')
            ->get(['id', 'name', 'supplier_code', 'state'])
            ->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name,
                'supplier_code' => $s->supplier_code, 'state' => $s->state,
            ]);

        $products = Product::query()->forCompany($company->id)
            ->with('unit:id,short_name,name')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'hsn_code', 'gst_rate', 'cost_price', 'unit_id', 'length_cm', 'width_cm', 'height_cm'])
            ->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku,
                'hsn_code' => $p->hsn_code, 'gst_rate' => (float) $p->gst_rate,
                'cost_price' => (float) $p->cost_price,
                'unit' => $p->unit?->short_name ?? $p->unit?->name,
                'length_cm' => (float) $p->length_cm,
                'width_cm' => (float) $p->width_cm,
                'height_cm' => (float) $p->height_cm,
            ]);

        return $this->ok([
            'suppliers' => $suppliers,
            'products'  => $products,
            'tax_rates' => [0, 5, 12, 18, 28],
        ]);
    }

    public function store(StorePurchaseRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $purchase = $this->purchases->create($company->id, $request->validated(), $request->user()->id);

        return $this->created(new PurchaseResource($purchase), 'Purchase saved as draft.');
    }

    public function show(Request $request, Purchase $purchase): JsonResponse
    {
        $this->allow($request, 'purchases.view');
        $this->sameCompany($request, $purchase);

        return $this->ok(new PurchaseResource($purchase->load(['supplier', 'items', 'createdBy:id,name', 'company:id,name,legal_name,gst_number,address,phone,state,state_code'])));
    }

    public function update(UpdatePurchaseRequest $request, Purchase $purchase): JsonResponse
    {
        $this->sameCompany($request, $purchase);
        $updated = $this->purchases->update($purchase, $request->validated(), $request->user()->id);

        return $this->ok(new PurchaseResource($updated), 'Purchase updated.');
    }

    public function confirm(Request $request, Purchase $purchase): JsonResponse
    {
        $this->allow($request, 'purchases.confirm');
        $this->sameCompany($request, $purchase);
        $confirmed = $this->purchases->confirm($purchase, $request->user()->id);

        return $this->ok(new PurchaseResource($confirmed), 'Purchase confirmed and stock posted.');
    }

    public function destroy(Request $request, Purchase $purchase): JsonResponse
    {
        $this->allow($request, 'purchases.delete');
        $this->sameCompany($request, $purchase);
        $this->purchases->cancel($purchase, $request->user()->id);

        return $this->message('Purchase cancelled.');
    }

    /** Batches (and their barcodes) created when this purchase was confirmed. */
    public function batches(Request $request, Purchase $purchase): JsonResponse
    {
        $this->allow($request, 'purchases.view');
        $this->sameCompany($request, $purchase);

        $batches = \App\Models\ProductBatch::forCompany($purchase->company_id)
            ->where('purchase_id', $purchase->id)
            ->with(['product:id,sku,name,mrp,retail_price', 'supplier:id,name', 'purchase:id,purchase_no'])
            ->orderBy('batch_no')
            ->get();

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

    private function sameCompany(Request $request, Purchase $purchase): void
    {
        abort_unless((string) $purchase->company_id === (string) $this->company($request)->id, 404);
    }
}
