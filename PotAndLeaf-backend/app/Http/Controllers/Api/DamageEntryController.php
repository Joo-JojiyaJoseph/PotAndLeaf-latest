<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Damage\StoreDamageEntryRequest;
use App\Http\Resources\DamageEntryResource;
use App\Models\Location;
use App\Models\Product;
use App\Services\DamageEntryService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DamageEntryController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(private readonly DamageEntryService $damage) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->filterCompany($request);
        abort_unless($request->user()->hasPermission('damage.view', $company->id), 403);

        return $this->ok(DamageEntryResource::collection(
            $this->damage->list($company->id, $request->only(['product_id', 'location_id', 'from', 'to', 'per_page']))
        ));
    }

    public function formData(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'damage.create');

        return $this->ok([
            'products' => Product::forCompany($company->id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'sku', 'name', 'current_stock'])
                ->map(fn ($p) => [
                    'id'            => $p->id,
                    'sku'           => $p->sku,
                    'name'          => $p->name,
                    'current_stock' => (float) $p->current_stock,
                ]),
            'locations' => Location::forCompany($company->id)
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'is_default']),
            'reasons' => [
                'Damaged in transit',
                'Expired / wilted',
                'Pest / disease',
                'Broken / crushed',
                'Weather damage',
                'Other',
            ],
        ]);
    }

    public function store(StoreDamageEntryRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $entry = $this->damage->create($company->id, $request->validated(), $request->user()?->id);

        return $this->created(new DamageEntryResource($entry), 'Damage entry recorded.');
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
