<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Production\StoreProductionOrderRequest;
use App\Http\Requests\Production\StoreProductionRequest;
use App\Http\Requests\Production\UpsertBomRequest;
use App\Http\Resources\BomResource;
use App\Http\Resources\ProductionOrderResource;
use App\Models\Bom;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderStage;
use App\Models\User;
use App\Services\ProductionService;
use App\Support\Api\ApiResponse;
use App\Support\Api\AssertsRecordCompany;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionController extends Controller
{
    use ApiResponse, AssertsRecordCompany, ResolvesFilterCompany;

    public function __construct(private readonly ProductionService $production) {}

    public function formData(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'production.view');

        $products = Product::forCompany($company->id)->orderBy('name')->get(['id', 'sku', 'name', 'current_stock', 'cost_price'])
            ->map(fn ($p) => [
                'id'            => $p->id,
                'sku'           => $p->sku,
                'name'          => $p->name,
                'current_stock' => (float) $p->current_stock,
                'cost_price'    => (float) $p->cost_price,
            ]);
        $units = ProductUnit::query()->where('company_id', $company->id)->orderBy('name')
            ->get(['id', 'name', 'short_name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'short_name' => $u->short_name]);
        $locations = Location::forCompany($company->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')
            ->get(['id', 'name', 'is_default'])
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'is_default' => (bool) $l->is_default]);
        $boms = $this->production->boms($company->id, activeOnly: true)
            ->map(fn ($b) => [
                'id'             => $b->id,
                'product_id'     => $b->product_id,
                'name'           => $b->name,
                'product_name'   => $b->product?->name,
                'output_qty'     => (float) $b->output_qty,
                'is_multi_stage' => $b->isMultiStage(),
                'items'          => $b->items->whereNull('bom_stage_id')->values()->map(fn ($i) => [
                    'component_product_id' => $i->component_product_id,
                    'qty'                  => (float) $i->qty,
                ]),
                'stages'         => $b->stages->map(fn ($s) => [
                    'name'  => $s->name,
                    'items' => $s->items->map(fn ($i) => [
                        'component_product_id' => $i->component_product_id,
                        'qty'                  => (float) $i->qty,
                    ])->values(),
                ])->values(),
            ]);

        $supervisors = User::query()
            ->activeMembers($company->id)
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
        $this->allow($request, 'production.view');

        return $this->ok(BomResource::collection($this->production->boms($this->listCompanyId($request))));
    }

    public function storeBom(UpsertBomRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $bom = $this->production->upsertBom($company->id, $request->validated(), $request->user()->id);

        return $this->created(new BomResource($bom), 'Bill of materials saved.');
    }

    public function updateBom(UpsertBomRequest $request, Bom $bom): JsonResponse
    {
        $this->assertRecordCompany($request, $bom, writable: true);
        $updated = $this->production->upsertBom($this->company($request)->id, ['id' => $bom->id] + $request->validated(), $request->user()->id);

        return $this->ok(new BomResource($updated), 'Bill of materials updated.');
    }

    public function destroyBom(Request $request, Bom $bom): JsonResponse
    {
        $this->allow($request, 'production.manage_bom');
        $this->assertRecordCompany($request, $bom, writable: true);
        $this->production->deleteBom($bom, $request->user()->id);

        return $this->message('Bill of materials deleted.');
    }

    // Orders
    public function orders(Request $request): JsonResponse
    {
        $this->allow($request, 'production.view');

        return $this->ok(ProductionOrderResource::collection($this->production->orders($this->listCompanyId($request), $request->only(['status', 'per_page']))));
    }

    public function storeOrder(StoreProductionOrderRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $order = $this->production->createOrder($company->id, $request->validated(), $request->user()->id);

        return $this->created(new ProductionOrderResource($order), 'Production order created.');
    }

    public function storeProduction(StoreProductionRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $order = $this->production->saveProduction($company->id, $request->validated(), $request->user()->id);
        $completed = $order->status === 'completed';

        return $this->created(
            new ProductionOrderResource($order),
            $completed ? 'Production completed — stock updated.' : 'Production saved as draft.',
        );
    }

    public function updateProduction(StoreProductionRequest $request, ProductionOrder $productionOrder): JsonResponse
    {
        $targetCompany = $this->company($request);
        if ((string) $productionOrder->company_id !== (string) $targetCompany->id) {
            abort_unless($request->user()->is_super_admin, 404, 'Switch to the record company to modify this item.');
        } else {
            $this->assertRecordCompany($request, $productionOrder, writable: true);
        }
        $order = $this->production->saveProduction(
            $this->company($request)->id,
            $request->validated(),
            $request->user()->id,
            $productionOrder,
        );
        $completed = $order->status === 'completed';

        return $this->ok(
            new ProductionOrderResource($order),
            $completed ? 'Production completed — stock updated.' : 'Production updated.',
        );
    }

    public function updateStatus(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        abort_unless($request->user()->is_super_admin, 403);
        $this->assertRecordCompany($request, $productionOrder, writable: true);
        $data = $request->validate([
            'status' => ['required', 'in:draft,in_progress,completed,cancelled'],
        ]);

        return $this->ok(
            new ProductionOrderResource($this->production->updateStatus($productionOrder, $data['status'], $request->user()->id)),
            'Production status updated.',
        );
    }

    public function showOrder(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        $this->allow($request, 'production.view');
        $this->assertRecordCompany($request, $productionOrder);

        return $this->ok(new ProductionOrderResource($productionOrder->load([
            'items', 'stages.supervisor:id,name', 'outputProduct:id,sku,name',
            'bom.items.component:id,sku,name,current_stock,cost_price',
            'bom.stages.items.component:id,sku,name,current_stock,cost_price',
            'batches', 'supervisor:id,name',
        ])));
    }

    public function startStage(Request $request, ProductionOrder $productionOrder, ProductionOrderStage $productionOrderStage): JsonResponse
    {
        $this->allow($request, 'production.complete');
        $this->assertRecordCompany($request, $productionOrder, writable: true);

        $stage = $this->production->startStage($productionOrder, $productionOrderStage, $request->user()->id);

        return $this->ok($stage, 'Production stage started.');
    }

    public function completeStage(Request $request, ProductionOrder $productionOrder, ProductionOrderStage $productionOrderStage): JsonResponse
    {
        $this->allow($request, 'production.complete');
        $this->assertRecordCompany($request, $productionOrder, writable: true);

        $order = $this->production->completeStage($productionOrder, $productionOrderStage, $request->user()->id);

        return $this->ok(new ProductionOrderResource($order), 'Production stage completed.');
    }

    public function estimate(Request $request): JsonResponse
    {
        $this->allow($request, 'production.view');
        $data = $request->validate([
            'bom_id'          => ['required_without:items', 'nullable', 'uuid'],
            'output_quantity' => ['required', 'numeric', 'gt:0'],
            'items'           => ['required_without:bom_id', 'array'],
            'items.*.component_product_id' => ['required_with:items', 'uuid'],
            'items.*.qty'                  => ['required_with:items', 'numeric', 'min:0'],
        ]);

        if (! empty($data['items'])) {
            return $this->ok($this->production->estimateFromLines(
                $this->company($request)->id,
                (float) $data['output_quantity'],
                $data['items'],
            ));
        }

        return $this->ok($this->production->estimate(
            $this->company($request)->id,
            $data['bom_id'],
            (float) $data['output_quantity'],
        ));
    }

    public function updateOrder(StoreProductionOrderRequest $request, ProductionOrder $productionOrder): JsonResponse
    {
        $this->assertRecordCompany($request, $productionOrder, writable: true);
        $updated = $this->production->updateOrder($productionOrder, $request->validated(), $request->user()->id);

        return $this->ok(new ProductionOrderResource($updated), 'Production order updated.');
    }

    public function complete(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        $this->allow($request, 'production.complete');
        $this->assertRecordCompany($request, $productionOrder, writable: true);

        return $this->ok(new ProductionOrderResource($this->production->complete($productionOrder, $request->user()->id)), 'Production completed — stock updated.');
    }

    public function destroyOrder(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        $this->allow($request, 'production.delete');
        $this->assertRecordCompany($request, $productionOrder, writable: true);
        $this->production->cancel($productionOrder, $request->user()->id);

        return $this->message('Production deleted.');
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

}
