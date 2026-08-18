<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\PurchaseOrderService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'po.view');

        return $this->ok(PurchaseOrderResource::collection($this->orders->list($company->id, $request->only(['search', 'status', 'per_page']))));
    }

    public function formData(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'po.create');

        $suppliers = Supplier::forCompany($company->id)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]);
        $products = Product::forCompany($company->id)->orderBy('name')
            ->get(['id', 'sku', 'name', 'cost_price', 'gst_rate'])
            ->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'cost_price' => (float) $p->cost_price, 'gst_rate' => (float) $p->gst_rate]);

        return $this->ok(['suppliers' => $suppliers, 'products' => $products]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'po.view');

        return $this->ok(['suggestions' => $this->orders->reorderSuggestions($company->id)]);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $po = $this->orders->create($company->id, $request->validated(), $request->user()->id);

        return $this->created(new PurchaseOrderResource($po), 'Purchase order created.');
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->allow($request, 'po.view');
        $this->sameCompany($request, $purchaseOrder);

        return $this->ok(new PurchaseOrderResource($purchaseOrder->load(['items', 'supplier:id,name'])));
    }

    public function send(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->allow($request, 'po.send');
        $this->sameCompany($request, $purchaseOrder);

        return $this->ok(new PurchaseOrderResource($this->orders->send($purchaseOrder)), 'PO marked as sent.');
    }

    public function convert(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->allow($request, 'po.convert');
        $this->sameCompany($request, $purchaseOrder);
        $result = $this->orders->convertToPurchase($purchaseOrder, $request->user()->id);

        return $this->ok(['purchase_id' => $result['purchase_id']], 'Draft GRN created from PO.');
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->allow($request, 'po.delete');
        $this->sameCompany($request, $purchaseOrder);
        $this->orders->cancel($purchaseOrder);

        return $this->message('Purchase order cancelled.');
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    private function sameCompany(Request $request, PurchaseOrder $po): void
    {
        abort_unless((string) $po->company_id === (string) $this->company($request)->id, 404);
    }
}
