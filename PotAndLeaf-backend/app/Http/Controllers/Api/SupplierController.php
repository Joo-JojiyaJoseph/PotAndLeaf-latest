<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\SupplierService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON API for suppliers. Thin: authorization + delegation to the same
 * SupplierService the Inertia side used. Company comes from ResolveApiCompany.
 */
class SupplierController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(private readonly SupplierService $suppliers) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'suppliers.view');

        $filters = $request->only(['search', 'status', 'sort', 'dir', 'per_page']);
        $paginated = $this->suppliers->list($company->id, $filters);

        return $this->ok(SupplierResource::collection($paginated));
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $supplier = $this->suppliers->create($company->id, $request->validated());

        return $this->created(new SupplierResource($supplier), 'Supplier created.');
    }

    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        $this->allow($request, 'suppliers.view');
        $this->sameCompany($request, $supplier);

        return $this->ok(new SupplierResource($supplier));
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $this->sameCompany($request, $supplier);
        $updated = $this->suppliers->update($supplier, $request->validated());

        return $this->ok(new SupplierResource($updated), 'Supplier updated.');
    }

    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        $this->allow($request, 'suppliers.delete');
        $this->sameCompany($request, $supplier);
        $this->suppliers->delete($supplier);

        return $this->message('Supplier moved to trash.');
    }

    public function purchaseHistory(Request $request, Supplier $supplier): JsonResponse
    {
        $this->allow($request, 'suppliers.view');
        $this->sameCompany($request, $supplier);

        $perPage = min(50, max(1, (int) $request->input('per_page', 15)));
        $paginated = \App\Models\Purchase::forCompany($supplier->company_id)
            ->where('supplier_id', $supplier->id)
            ->withCount('items')
            ->orderByDesc('purchase_date')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return $this->ok(\App\Http\Resources\PurchaseResource::collection($paginated));
    }

    private function allow(Request $request, string $permission): void
    {
        $company = $request->attributes->get('company');
        abort_unless($request->user()->hasPermission($permission, $company->id), 403);
    }

    private function sameCompany(Request $request, Supplier $supplier): void
    {
        $company = $request->attributes->get('company');
        abort_unless((string) $supplier->company_id === (string) $company->id, 404);
    }

    public function toggleStatus(Request $request, Supplier $supplier): JsonResponse
    {
        $company = $request->attributes->get('company');
        abort_unless($request->user()->hasPermission('suppliers.update', $company->id), 403);
        abort_unless((string) $supplier->company_id === (string) $company->id, 404);
        $data = $request->validate(['status' => ['required', 'in:active,inactive']]);
        $supplier->update(['status' => $data['status']]);

        return $this->ok(['id' => $supplier->id, 'status' => $supplier->status], 'Status updated.');
    }
}
