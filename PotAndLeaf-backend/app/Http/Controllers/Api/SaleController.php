<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sale\StoreSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Services\SaleService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(private readonly SaleService $sales) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->filterCompany($request);
        abort_unless($request->user()->hasPermission('sales.view', $company->id), 403);

        return $this->ok(SaleResource::collection($this->sales->list($company->id, $request->only(['search', 'status', 'per_page']))));
    }

    public function formData(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'sales.create');

        $customers = Customer::forCompany($company->id)->where('status', 'active')->orderBy('name')
            ->get(['id', 'name', 'type', 'loyalty_points'])
            ->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'type' => $c->type?->value,
                'loyalty_points' => (int) $c->loyalty_points,
            ]);

        $settings = app(\App\Services\SettingsService::class)->all($company->id);

        $products = Product::forCompany($company->id)->orderBy('name')
            ->get(['id', 'sku', 'name', 'hsn_code', 'gst_rate', 'current_stock', 'retail_price', 'wholesale_price', 'dealer_price', 'mrp'])
            ->map(fn ($p) => [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'hsn_code' => $p->hsn_code,
                'gst_rate' => (float) $p->gst_rate, 'current_stock' => (float) $p->current_stock,
                'retail_price' => (float) $p->retail_price, 'wholesale_price' => (float) $p->wholesale_price,
                'dealer_price' => (float) $p->dealer_price, 'mrp' => (float) $p->mrp,
            ]);

        $locations = Location::forCompany($company->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')
            ->get(['id', 'name', 'is_default'])
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'is_default' => (bool) $l->is_default]);

        return $this->ok([
            'customers' => $customers,
            'products'  => $products,
            'locations' => $locations,
            'settings'  => [
                'loyalty_earn_rupees'        => (float) $settings['loyalty_earn_rupees'],
                'loyalty_earn_points'        => (int) $settings['loyalty_earn_points'],
                'loyalty_redeem_rupees'      => (float) $settings['loyalty_redeem_rupees'],
                'loyalty_redeem_cap_percent' => (float) $settings['loyalty_redeem_cap_percent'],
            ],
        ]);
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $sale = $this->sales->create($company->id, $request->validated(), $request->user()->id);

        return $this->created(new SaleResource($sale), 'Sale saved as draft.');
    }

    public function show(Request $request, Sale $sale): JsonResponse
    {
        $this->allow($request, 'sales.view');
        $this->sameCompany($request, $sale);

        return $this->ok(new SaleResource($sale->load(['items', 'customer:id,name,type', 'createdBy:id,name', 'company:id,name,legal_name,gst_number,address,phone,email,state,state_code'])));
    }

    public function confirm(Request $request, Sale $sale): JsonResponse
    {
        $this->allow($request, 'sales.confirm');
        $this->sameCompany($request, $sale);

        return $this->ok(new SaleResource($this->sales->confirm($sale, $request->user()->id)), 'Sale confirmed — stock updated.');
    }

    public function destroy(Request $request, Sale $sale): JsonResponse
    {
        $this->allow($request, 'sales.delete');
        $this->sameCompany($request, $sale);
        $this->sales->cancel($sale, $request->user()->id);

        return $this->message('Sale cancelled.');
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    private function sameCompany(Request $request, Sale $sale): void
    {
        abort_unless((string) $sale->company_id === (string) $this->company($request)->id, 404);
    }
}
