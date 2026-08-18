<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdvanceOrder\StoreAdvanceOrderRequest;
use App\Http\Resources\AdvanceOrderResource;
use App\Models\AdvanceOrder;
use App\Models\Customer;
use App\Models\Product;
use App\Services\AdvanceOrderService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvanceOrderController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(private readonly AdvanceOrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'advance.view');

        return $this->ok(AdvanceOrderResource::collection($this->orders->list($company->id, $request->only(['search', 'status', 'per_page']))));
    }

    public function formData(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'advance.create');

        $customers = Customer::forCompany($company->id)->where('status', 'active')->orderBy('name')
            ->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]);
        $products = Product::forCompany($company->id)->orderBy('name')
            ->get(['id', 'sku', 'name', 'retail_price', 'gst_rate'])
            ->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'retail_price' => (float) $p->retail_price, 'gst_rate' => (float) $p->gst_rate]);

        return $this->ok(['customers' => $customers, 'products' => $products]);
    }

    public function store(StoreAdvanceOrderRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $order = $this->orders->create($company->id, $request->validated(), $request->user()->id);

        return $this->created(new AdvanceOrderResource($order), 'Advance order booked.');
    }

    public function show(Request $request, AdvanceOrder $advanceOrder): JsonResponse
    {
        $this->allow($request, 'advance.view');
        $this->sameCompany($request, $advanceOrder);

        return $this->ok(new AdvanceOrderResource($advanceOrder->load(['items', 'customer:id,name,type'])));
    }

    public function fulfill(Request $request, AdvanceOrder $advanceOrder): JsonResponse
    {
        $this->allow($request, 'advance.fulfill');
        $this->sameCompany($request, $advanceOrder);
        $result = $this->orders->fulfill($advanceOrder, $request->user()->id);

        return $this->ok(['sale_id' => $result['sale_id']], 'Draft sale created from advance order.');
    }

    public function destroy(Request $request, AdvanceOrder $advanceOrder): JsonResponse
    {
        $this->allow($request, 'advance.delete');
        $this->sameCompany($request, $advanceOrder);
        $this->orders->cancel($advanceOrder);

        return $this->message('Advance order cancelled.');
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    private function sameCompany(Request $request, AdvanceOrder $order): void
    {
        abort_unless((string) $order->company_id === (string) $this->company($request)->id, 404);
    }
}
