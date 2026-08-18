<?php

namespace App\Http\Controllers;

use App\Enums\SupplierStatus;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Models\Team;
use App\Services\SupplierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin controller: authorise, hand validated data to the service, return a
 * response. No business logic, no queries. Every master module's controller
 * looks exactly like this.
 */
class SupplierController extends Controller
{
    public function __construct(private readonly SupplierService $suppliers) {}

    public function index(Request $request, Team $current_team): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $filters = $request->only(['search', 'status', 'sort', 'dir', 'per_page']);

        return Inertia::render('suppliers/index', [
            'team'          => $current_team->slug,
            'suppliers'     => SupplierResource::collection(
                $this->suppliers->list($current_team->id, $filters)
            ),
            'filters'       => $filters,
            'statusOptions' => SupplierStatus::options(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Supplier::class);

        return Inertia::render('suppliers/form', [
            'team'          => request()->route('current_team')->slug,
            'statusOptions' => SupplierStatus::options(),
        ]);
    }

    public function store(StoreSupplierRequest $request, Team $current_team): RedirectResponse
    {
        $supplier = $this->suppliers->create($current_team->id, $request->validated());

        return to_route('suppliers.index', ['current_team' => $current_team])
            ->with('success', "Supplier {$supplier->name} created.");
    }

    public function show(Team $current_team, Supplier $supplier): Response
    {
        $this->authorize('view', $supplier);

        return Inertia::render('suppliers/show', [
            'supplier' => new SupplierResource($supplier),
        ]);
    }

    public function edit(Team $current_team, Supplier $supplier): Response
    {
        $this->authorize('update', $supplier);

        return Inertia::render('suppliers/form', [
            'team'          => $current_team->slug,
            'supplier'      => new SupplierResource($supplier),
            'statusOptions' => SupplierStatus::options(),
        ]);
    }

    public function update(UpdateSupplierRequest $request, Team $current_team, Supplier $supplier): RedirectResponse
    {
        $this->suppliers->update($supplier, $request->validated());

        return to_route('suppliers.index', ['current_team' => $current_team])
            ->with('success', "Supplier {$supplier->name} updated.");
    }

    public function destroy(Team $current_team, Supplier $supplier): RedirectResponse
    {
        $this->authorize('delete', $supplier);
        $this->suppliers->delete($supplier);

        return back()->with('success', 'Supplier moved to trash.');
    }

    public function restore(Team $current_team, string $supplier): RedirectResponse
    {
        $restored = $this->suppliers->restore($current_team->id, $supplier);
        abort_if($restored === null, 404);
        $this->authorize('restore', $restored);

        return back()->with('success', 'Supplier restored.');
    }
}
