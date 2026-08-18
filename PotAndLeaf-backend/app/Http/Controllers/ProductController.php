<?php

namespace App\Http\Controllers;

use App\Enums\ProductStatus;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\Team;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    public function index(Request $request, Team $current_team): Response
    {
        $this->authorize('viewAny', Product::class);

        $filters = $request->only([
            'search', 'status', 'category_id', 'brand_id', 'low_stock', 'sort', 'dir', 'per_page',
        ]);

        return Inertia::render('products/index', [
            'team'          => $current_team->slug,
            'products'      => ProductResource::collection($this->products->list($current_team->id, $filters)),
            'filters'       => $filters,
            'statusOptions' => ProductStatus::options(),
            'categories'    => $this->options(ProductCategory::class, $current_team->id),
            'brands'        => $this->options(ProductBrand::class, $current_team->id),
        ]);
    }

    public function create(Team $current_team): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('products/form', $this->formData($current_team));
    }

    public function store(StoreProductRequest $request, Team $current_team): RedirectResponse
    {
        $product = $this->products->create($current_team->id, $request->validated());

        return to_route('products.index', ['current_team' => $current_team])
            ->with('success', "Product {$product->name} created.");
    }

    public function show(Team $current_team, Product $product): Response
    {
        $this->authorize('view', $product);

        return Inertia::render('products/show', [
            'product' => new ProductResource($product->load(['category', 'brand', 'unit', 'suppliers'])),
        ]);
    }

    public function edit(Team $current_team, Product $product): Response
    {
        $this->authorize('update', $product);

        return Inertia::render('products/form', array_merge(
            $this->formData($current_team),
            ['product' => new ProductResource($product->load(['category', 'brand', 'unit', 'suppliers']))],
        ));
    }

    public function update(UpdateProductRequest $request, Team $current_team, Product $product): RedirectResponse
    {
        $this->products->update($product, $request->validated());

        return to_route('products.index', ['current_team' => $current_team])
            ->with('success', "Product {$product->name} updated.");
    }

    public function destroy(Team $current_team, Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);
        $this->products->delete($product);

        return back()->with('success', 'Product moved to trash.');
    }

    public function restore(Team $current_team, string $product): RedirectResponse
    {
        $restored = $this->products->restore($current_team->id, $product);
        abort_if($restored === null, 404);
        $this->authorize('restore', $restored);

        return back()->with('success', 'Product restored.');
    }

    /** Shared props for the create/edit form. */
    private function formData(Team $team): array
    {
        return [
            'team'          => $team->slug,
            'statusOptions' => ProductStatus::options(),
            'categories'    => $this->options(ProductCategory::class, $team->id),
            'brands'        => $this->options(ProductBrand::class, $team->id),
            'units'         => $this->options(ProductUnit::class, $team->id),
            'suppliers'     => Supplier::query()->forTeam($team->id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($s) => ['value' => $s->id, 'label' => $s->name]),
        ];
    }

    /** @param class-string $modelClass */
    private function options(string $modelClass, int|string $teamId)
    {
        return $modelClass::query()
            ->where('team_id', $teamId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($m) => ['value' => $m->id, 'label' => $m->name]);
    }
}
