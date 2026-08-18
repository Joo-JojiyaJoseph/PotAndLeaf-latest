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
use App\Support\Api\AssertsRecordCompany;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseReturnController extends Controller
{
    use ApiResponse, AssertsRecordCompany, ResolvesFilterCompany;

    public function __construct(
        private readonly PurchaseReturnService $returns,
        private readonly PurchaseReturnRepositoryInterface $returnRepo,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->allow($request, 'purchase_returns.view');

        $filters = $request->only(['search', 'status', 'per_page']);

        return $this->ok(PurchaseReturnResource::collection($this->returns->list($this->listCompanyId($request), $filters)));
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

        $lines = $purchase->items->map(function ($item) use ($returned, $company) {
            $already = (float) ($returned[$item->id] ?? 0);
            $returnable = max(0, (float) $item->qty - $already);

            $batches = \App\Models\ProductBatch::forCompany($company->id)
                ->where('remaining_qty', '>', 0)
                ->where(function ($q) use ($item) {
                    $q->where('purchase_item_id', $item->id)
                        ->orWhereHas('product', fn ($p) => $p->where('parent_product_id', $item->product_id));
                })
                ->with('product:id,name,sku')
                ->orderBy('batch_no')
                ->get()
                ->map(fn ($b) => [
                    'id'            => $b->id,
                    'batch_no'      => $b->batch_no,
                    'barcode'       => $b->barcode,
                    'product_id'    => $b->product_id,
                    'product_name'  => $b->product?->name,
                    'remaining_qty' => (float) $b->remaining_qty,
                ])
                ->values();

            return [
                'purchase_item_id' => $item->id,
                'product_id'       => $item->product_id,
                'product_name'     => $item->product_name,
                'qty'              => (float) $item->qty,
                'returned'         => $already,
                'returnable'       => $returnable,
                'rate'             => (float) $item->rate,
                'gst_rate'         => (float) $item->gst_rate,
                'batches'          => $batches,
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
        $this->assertRecordCompany($request, $purchaseReturn);

        return $this->ok(new PurchaseReturnResource(
            $purchaseReturn->load(['supplier', 'purchase:id,purchase_no', 'items'])
        ));
    }

    public function confirm(Request $request, PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->allow($request, 'purchase_returns.confirm');
        $this->assertRecordCompany($request, $purchaseReturn, writable: true, writableMessage: 'Switch to the return company to confirm or cancel.');

        return $this->ok(
            new PurchaseReturnResource($this->returns->confirm($purchaseReturn, $request->user()->id)),
            'Return confirmed and stock reversed.'
        );
    }

    public function destroy(Request $request, PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->allow($request, 'purchase_returns.delete');
        $this->assertRecordCompany($request, $purchaseReturn, writable: true, writableMessage: 'Switch to the return company to confirm or cancel.');
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
}
