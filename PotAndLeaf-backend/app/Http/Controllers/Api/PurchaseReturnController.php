<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseReturn\StorePurchaseReturnRequest;
use App\Http\Resources\PurchaseReturnResource;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Repositories\Contracts\PurchaseReturnRepositoryInterface;
use App\Services\PurchaseReturnService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseReturnController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(
        private readonly PurchaseReturnService $returns,
        private readonly PurchaseReturnRepositoryInterface $returnRepo,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'purchase_returns.view');

        $filters = $request->only(['search', 'status', 'per_page']);

        return $this->ok(PurchaseReturnResource::collection($this->returns->list($company->id, $filters)));
    }

    /**
     * The return form's source: a confirmed purchase with each line's
     * still-returnable quantity (original qty minus already-confirmed returns).
     */
    public function source(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'purchase_returns.create');
        $request->validate(['purchase_id' => ['required', 'uuid']]);

        $purchase = Purchase::forCompany($company->id)->with('items')->find($request->query('purchase_id'));
        abort_unless($purchase && $purchase->status === 'confirmed', 404);

        $returned = $this->returnRepo->returnedQtyByPurchaseItem($purchase->id);

        $lines = $purchase->items->map(function ($item) use ($returned) {
            $already = (float) ($returned[$item->id] ?? 0);
            return [
                'purchase_item_id' => $item->id,
                'product_id'       => $item->product_id,
                'product_name'     => $item->product_name,
                'qty'              => (float) $item->qty,
                'returned'         => $already,
                'returnable'       => max(0, (float) $item->qty - $already),
                'rate'             => (float) $item->rate,
                'gst_rate'         => (float) $item->gst_rate,
            ];
        })->values();

        return $this->ok([
            'purchase' => [
                'id'            => $purchase->id,
                'purchase_no'   => $purchase->purchase_no,
                'is_interstate' => (bool) $purchase->is_interstate,
            ],
            'items' => $lines,
        ]);
    }

    public function store(StorePurchaseReturnRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $return = $this->returns->create($company->id, $request->validated(), $request->user()->id);

        return $this->created(new PurchaseReturnResource($return), 'Return saved as draft.');
    }

    public function show(Request $request, PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->allow($request, 'purchase_returns.view');
        $this->sameCompany($request, $purchaseReturn);

        return $this->ok(new PurchaseReturnResource(
            $purchaseReturn->load(['supplier', 'purchase:id,purchase_no', 'items'])
        ));
    }

    public function confirm(Request $request, PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->allow($request, 'purchase_returns.confirm');
        $this->sameCompany($request, $purchaseReturn);

        return $this->ok(
            new PurchaseReturnResource($this->returns->confirm($purchaseReturn, $request->user()->id)),
            'Return confirmed and stock reversed.'
        );
    }

    public function destroy(Request $request, PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->allow($request, 'purchase_returns.delete');
        $this->sameCompany($request, $purchaseReturn);
        $this->returns->cancel($purchaseReturn, $request->user()->id);

        return $this->message('Return cancelled.');
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    private function sameCompany(Request $request, PurchaseReturn $return): void
    {
        abort_unless((string) $return->company_id === (string) $this->company($request)->id, 404);
    }
}
