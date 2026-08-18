<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockVerification\RejectStockVerificationRequest;
use App\Http\Requests\StockVerification\StoreStockVerificationRequest;
use App\Http\Resources\StockVerificationResource;
use App\Models\Product;
use App\Models\StockVerification;
use App\Services\StockVerificationService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockVerificationController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(private readonly StockVerificationService $verifications) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'stock_verifications.view');

        return $this->ok(StockVerificationResource::collection(
            $this->verifications->list($company->id, $request->only(['search', 'status', 'per_page']))
        ));
    }

    /** Products with their current system stock, to seed the count sheet. */
    public function formData(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'stock_verifications.create');

        $products = Product::forCompany($company->id)
            ->with('unit:id,short_name,name')
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'current_stock', 'unit_id'])
            ->map(fn ($p) => [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name,
                'system_qty' => (float) $p->current_stock,
                'unit' => $p->unit?->short_name ?? $p->unit?->name,
            ]);

        return $this->ok(['products' => $products]);
    }

    public function store(StoreStockVerificationRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $v = $this->verifications->create($company->id, $request->validated(), $request->user()->id);

        return $this->created(new StockVerificationResource($v), 'Stock count saved as draft.');
    }

    public function show(Request $request, StockVerification $stockVerification): JsonResponse
    {
        $this->allow($request, 'stock_verifications.view');
        $this->sameCompany($request, $stockVerification);

        return $this->ok(new StockVerificationResource($stockVerification->load('items')));
    }

    public function submit(Request $request, StockVerification $stockVerification): JsonResponse
    {
        $this->allow($request, 'stock_verifications.create');
        $this->sameCompany($request, $stockVerification);

        return $this->ok(
            new StockVerificationResource($this->verifications->submit($stockVerification, $request->user()->id)),
            'Count submitted for HO approval.'
        );
    }

    public function approve(Request $request, StockVerification $stockVerification): JsonResponse
    {
        $this->allow($request, 'stock_verifications.approve');
        $this->sameCompany($request, $stockVerification);

        return $this->ok(
            new StockVerificationResource($this->verifications->approve($stockVerification, $request->user()->id)),
            'Count approved — stock adjusted to match the physical count.'
        );
    }

    public function reject(RejectStockVerificationRequest $request, StockVerification $stockVerification): JsonResponse
    {
        $this->sameCompany($request, $stockVerification);

        return $this->ok(
            new StockVerificationResource(
                $this->verifications->reject($stockVerification, $request->validated()['reason'], $request->user()->id)
            ),
            'Count rejected.'
        );
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    private function sameCompany(Request $request, StockVerification $v): void
    {
        abort_unless((string) $v->company_id === (string) $this->company($request)->id, 404);
    }
}
