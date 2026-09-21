<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backorder\FulfillBackorderRequest;
use App\Http\Requests\Backorder\StoreBackorderRequest;
use App\Http\Resources\BackorderResource;
use App\Http\Resources\StockTransferResource;
use App\Models\Backorder;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Services\BackorderService;
use App\Support\Api\ApiResponse;
use App\Support\Api\AssertsRecordCompany;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BackorderController extends Controller
{
    use ApiResponse, AssertsRecordCompany, ResolvesFilterCompany;

    public function __construct(private readonly BackorderService $backorders) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('backorder.view', $this->company($request)->id), 403);

        return $this->ok(BackorderResource::collection(
            $this->backorders->list($this->listCompanyId($request), $request->only(['search', 'status', 'per_page']))
        ));
    }

    public function formData(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'backorder.create');

        $customers = Customer::forCompany($company->id)->where('status', 'active')->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'type' => $c->type?->value]);

        $products = Product::forCompany($company->id)->orderBy('name')
            ->get(['id', 'sku', 'name', 'current_stock', 'retail_price', 'gst_rate'])
            ->map(fn ($p) => [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name,
                'current_stock' => (float) $p->current_stock,
                'retail_price' => (float) $p->retail_price, 'gst_rate' => (float) $p->gst_rate,
            ]);

        $locations = Location::forCompany($company->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name]);

        return $this->ok(compact('customers', 'products', 'locations'));
    }

    public function store(StoreBackorderRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $result = $this->backorders->requestShortage($company->id, $request->validated(), $request->user()->id);

        return $this->shortageCreated($result);
    }

    public function show(Request $request, Backorder $backorder): JsonResponse
    {
        $this->allow($request, 'backorder.view');
        $this->assertRecordCompany($request, $backorder);

        return $this->ok(new BackorderResource($backorder->load(['items.product:id,current_stock', 'customer:id,name,type'])));
    }

    public function fulfill(FulfillBackorderRequest $request, Backorder $backorder): JsonResponse
    {
        $this->assertRecordCompany($request, $backorder, writable: true);
        $result = $this->backorders->fulfill($backorder, $request->validated('items'), $request->user()->id);

        return $this->ok([
            'order'   => new BackorderResource($result['order']),
            'sale_id' => $result['sale_id'],
        ], 'Draft sale created from backorder fulfillment — confirm to post stock.');
    }

    public function destroy(Request $request, Backorder $backorder): JsonResponse
    {
        $this->allow($request, 'backorder.delete');
        $this->assertRecordCompany($request, $backorder, writable: true);
        $this->backorders->cancel($backorder);

        return $this->message('Backorder cancelled.');
    }

    public function createFromSale(Request $request, Sale $sale): JsonResponse
    {
        $this->allow($request, 'backorder.create');
        $this->assertRecordCompany($request, $sale, writable: true);
        $result = $this->backorders->createFromSale($sale, $request->user()->id);

        return $this->shortageCreated($result, fromSale: true);
    }

    /**
     * @param  array{backorder: ?Backorder, transfers: list<\App\Models\StockTransfer>, resolution: list<array<string,mixed>>}  $result
     */
    private function shortageCreated(array $result, bool $fromSale = false): JsonResponse
    {
        $backorder = $result['backorder'];
        $transfers = $result['transfers'];

        if ($backorder && $transfers === []) {
            $msg = $fromSale ? 'Backorder created for shortage lines.' : 'Backorder created.';

            return $this->created(new BackorderResource($backorder), $msg);
        }

        $payload = [
            'backorder'          => $backorder ? (new BackorderResource($backorder))->resolve() : null,
            'transfer_requests'  => collect($transfers)
                ->map(fn ($t) => (new StockTransferResource($t))->resolve())
                ->values()
                ->all(),
            'resolution'         => $result['resolution'],
        ];

        $message = match (true) {
            $backorder !== null => 'Stock was found at another branch for some items. Transfer request(s) created; remaining qty recorded as a backorder.',
            default => 'Stock is available at another branch. Inter-company transfer request created instead of a backorder.',
        };

        return $this->created($payload, $message);
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
