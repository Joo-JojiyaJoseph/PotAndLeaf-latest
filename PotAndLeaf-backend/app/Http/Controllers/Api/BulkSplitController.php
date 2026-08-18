<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkSplit\StoreBulkSplitRequest;
use App\Http\Resources\BulkSplitResource;
use App\Models\BulkSplit;
use App\Models\Product;
use App\Services\BulkSplitService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BulkSplitController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(private readonly BulkSplitService $splits) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'bulk_splits.view');

        return $this->ok(BulkSplitResource::collection($this->splits->list($company->id, $request->only(['search', 'status', 'per_page']))));
    }

    public function formData(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'bulk_splits.create');

        $products = Product::forCompany($company->id)
            ->with('unit:id,short_name,name')
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'current_stock', 'cost_price', 'unit_id'])
            ->map(fn ($p) => [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name,
                'current_stock' => (float) $p->current_stock, 'cost_price' => (float) $p->cost_price,
                'unit' => $p->unit?->short_name ?? $p->unit?->name,
            ]);

        return $this->ok(['products' => $products]);
    }

    public function store(StoreBulkSplitRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $split = $this->splits->create($company->id, $request->validated(), $request->user()->id);

        return $this->created(new BulkSplitResource($split), 'Split saved as draft.');
    }

    public function show(Request $request, BulkSplit $bulkSplit): JsonResponse
    {
        $this->allow($request, 'bulk_splits.view');
        $this->sameCompany($request, $bulkSplit);

        return $this->ok(new BulkSplitResource($bulkSplit->load(['items.units', 'sourceProduct:id,sku,name'])));
    }

    public function confirm(Request $request, BulkSplit $bulkSplit): JsonResponse
    {
        $this->allow($request, 'bulk_splits.confirm');
        $this->sameCompany($request, $bulkSplit);

        return $this->ok(new BulkSplitResource($this->splits->confirm($bulkSplit, $request->user()->id)), 'Split confirmed — stock updated.');
    }

    public function destroy(Request $request, BulkSplit $bulkSplit): JsonResponse
    {
        $this->allow($request, 'bulk_splits.delete');
        $this->sameCompany($request, $bulkSplit);
        $this->splits->cancel($bulkSplit, $request->user()->id);

        return $this->message('Split cancelled.');
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    private function sameCompany(Request $request, BulkSplit $split): void
    {
        abort_unless((string) $split->company_id === (string) $this->company($request)->id, 404);
    }
}
