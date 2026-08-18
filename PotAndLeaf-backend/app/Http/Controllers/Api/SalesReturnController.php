<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesReturn\StoreSalesReturnRequest;
use App\Http\Resources\SalesReturnResource;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Repositories\Contracts\SalesReturnRepositoryInterface;
use App\Services\SalesReturnService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesReturnController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(
        private readonly SalesReturnService $returns,
        private readonly SalesReturnRepositoryInterface $returnRepo,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'sales_returns.view');

        return $this->ok(SalesReturnResource::collection(
            $this->returns->list($company->id, $request->only(['search', 'status', 'per_page']))
        ));
    }

    public function source(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'sales_returns.create');
        $request->validate(['sale_id' => ['required', 'uuid']]);

        $sale = Sale::forCompany($company->id)->with(['items', 'customer:id,name'])->find($request->query('sale_id'));
        abort_unless($sale && $sale->status === 'confirmed', 404);

        $returned = $this->returnRepo->returnedQtyBySaleItem($sale->id);

        $lines = $sale->items->map(function ($item) use ($returned) {
            $already = (float) ($returned[$item->id] ?? 0);

            return [
                'sale_item_id' => $item->id,
                'product_id'   => $item->product_id,
                'product_name' => $item->product_name,
                'qty'          => (float) $item->qty,
                'returned'     => $already,
                'returnable'   => max(0, (float) $item->qty - $already),
                'rate'         => (float) $item->rate,
                'discount'     => (float) $item->discount,
                'gst_rate'     => (float) $item->gst_rate,
            ];
        })->values();

        return $this->ok([
            'sale' => [
                'id'            => $sale->id,
                'sale_no'       => $sale->sale_no,
                'is_interstate' => (bool) $sale->is_interstate,
                'customer_name' => $sale->customer_name,
            ],
            'items' => $lines,
        ]);
    }

    public function store(StoreSalesReturnRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $return = $this->returns->create($company->id, $request->validated(), $request->user()->id);

        return $this->created(new SalesReturnResource($return), 'Sales return saved as draft.');
    }

    public function show(Request $request, SalesReturn $salesReturn): JsonResponse
    {
        $this->allow($request, 'sales_returns.view');
        $this->sameCompany($request, $salesReturn);

        return $this->ok(new SalesReturnResource(
            $salesReturn->load(['customer', 'sale:id,sale_no', 'items'])
        ));
    }

    public function confirm(Request $request, SalesReturn $salesReturn): JsonResponse
    {
        $this->allow($request, 'sales_returns.confirm');
        $this->sameCompany($request, $salesReturn);

        return $this->ok(
            new SalesReturnResource($this->returns->confirm($salesReturn, $request->user()->id)),
            'Return confirmed — stock restored and credit note applied.'
        );
    }

    public function destroy(Request $request, SalesReturn $salesReturn): JsonResponse
    {
        $this->allow($request, 'sales_returns.delete');
        $this->sameCompany($request, $salesReturn);
        $this->returns->cancel($salesReturn, $request->user()->id);

        return $this->message('Sales return cancelled.');
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    private function sameCompany(Request $request, SalesReturn $return): void
    {
        abort_unless((string) $return->company_id === (string) $this->company($request)->id, 404);
    }
}
