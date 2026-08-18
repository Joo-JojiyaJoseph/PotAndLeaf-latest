<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Production\StoreProductionOrderRequest;
use App\Http\Requests\Production\UpsertBomRequest;
use App\Http\Resources\BomResource;
use App\Http\Resources\ProductionOrderResource;
use App\Models\Bom;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ProductionOrder;
use App\Services\ProductionService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(private readonly ProductionService $production) {}

    public function formData(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'production.view');

        $products = Product::forCompany($company->id)->orderBy('name')->get(['id', 'sku', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name]);
        $units = ProductUnit::query()->where('company_id', $company->id)->orderBy('name')
            ->get(['id', 'name', 'short_name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'short_name' => $u->short_name]);
        $locations = Location::forCompany($company->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')
            ->get(['id', 'name', 'is_default'])
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'is_default' => (bool) $l->is_default]);
        $boms = $this->production->boms($company->id)
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'product_name' => $b->product?->name, 'output_qty' => (float) $b->output_qty]);

        $supervisors = \App\Models\User::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        return $this->ok([
            'products'    => $products,
            'units'       => $units,
            'locations'   => $locations,
            'boms'        => $boms,
            'supervisors' => $supervisors,
        ]);
    }

    // BOMs
    public function boms(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'production.view');

        return $this->ok(BomResource::collection($this->production->boms($company->id)));
    }

    public function storeBom(UpsertBomRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $bom = $this->production->upsertBom($company->id, $request->validated());

        return $this->created(new BomResource($bom), 'Bill of materials saved.');
    }

    public function updateBom(UpsertBomRequest $request, Bom $bom): JsonResponse
    {
        $this->sameCompany($request, $bom);
        $updated = $this->production->upsertBom($this->company($request)->id, ['id' => $bom->id] + $request->validated());

        return $this->ok(new BomResource($updated), 'Bill of materials updated.');
    }

    public function destroyBom(Request $request, Bom $bom): JsonResponse
    {
        $this->allow($request, 'production.manage_bom');
        $this->sameCompany($request, $bom);
        $this->production->deleteBom($bom);

        return $this->message('Bill of materials deleted.');
    }

    // Orders
    public function orders(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'production.view');

        return $this->ok(ProductionOrderResource::collection($this->production->orders($company->id, $request->only(['status', 'per_page']))));
    }

    public function storeOrder(StoreProductionOrderRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $order = $this->production->createOrder($company->id, $request->validated(), $request->user()->id);

        return $this->created(new ProductionOrderResource($order), 'Production order created.');
    }

    public function showOrder(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        $this->allow($request, 'production.view');
        $this->sameCompany($request, $productionOrder);

        return $this->ok(new ProductionOrderResource($productionOrder->load(['items', 'outputProduct:id,sku,name', 'bom:id,name', 'batches'])));
    }

    public function updateOrder(StoreProductionOrderRequest $request, ProductionOrder $productionOrder): JsonResponse
    {
        $this->sameCompany($request, $productionOrder);
        $updated = $this->production->updateOrder($productionOrder, $request->validated());

        return $this->ok(new ProductionOrderResource($updated), 'Production order updated.');
    }

    public function complete(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        $this->allow($request, 'production.complete');
        $this->sameCompany($request, $productionOrder);

        return $this->ok(new ProductionOrderResource($this->production->complete($productionOrder, $request->user()->id)), 'Production completed — stock updated.');
    }

    public function destroyOrder(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        $this->allow($request, 'production.delete');
        $this->sameCompany($request, $productionOrder);
        $this->production->cancel($productionOrder, $request->user()->id);

        return $this->message('Production order cancelled.');
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    private function sameCompany(Request $request, $model): void
    {
        abort_unless((string) $model->company_id === (string) $this->company($request)->id, 404);
    }
}
