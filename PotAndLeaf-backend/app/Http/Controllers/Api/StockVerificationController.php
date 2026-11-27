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
use App\Support\Api\AssertsRecordCompany;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockVerificationController extends Controller
{
    use ApiResponse, AssertsRecordCompany, ResolvesFilterCompany;

    public function __construct(private readonly StockVerificationService $verifications) {}

    public function index(Request $request): JsonResponse
    {
        $this->allow($request, 'stock_verifications.view');

        return $this->ok(StockVerificationResource::collection(
            $this->verifications->list($this->listCompanyId($request), $request->only(['search', 'status', 'per_page', 'from', 'to']))
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
            ->get(['id', 'sku', 'name', 'current_stock', 'unit_id', 'images'])
            ->map(fn ($p) => [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'image' => \App\Support\Media\MediaUrl::resolveMany($p->images ?? [])[0] ?? null,
                'system_qty' => (float) $p->current_stock,
                'unit' => $p->unit?->short_name ?? $p->unit?->name,
            ]);

        // Batches with stock, oldest first: the count sheet lists one row per batch.
        $batches = \App\Models\ProductBatch::query()
            ->where('company_id', $company->id)
            ->where('remaining_qty', '>', 0)
            ->orderBy('received_at')->orderBy('created_at')
            ->get(['id', 'product_id', 'batch_no', 'barcode', 'remaining_qty', 'received_at'])
            ->map(fn ($b) => [
                'id' => $b->id, 'product_id' => $b->product_id, 'batch_no' => $b->batch_no, 'barcode' => $b->barcode,
                'system_qty' => (float) $b->remaining_qty, 'received_at' => optional($b->received_at)->toDateString(),
            ]);

        $locations = \App\Models\Location::forCompany($company->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')
            ->get(['id', 'name', 'type', 'is_default'])
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'type' => $l->type, 'is_default' => (bool) $l->is_default]);

        // With several branches / godowns each count is for ONE location, so send what each holds.
        $locationStock = [];
        if ($locations->count() > 1) {
            foreach (app(\App\Services\LocationStockService::class)->balances($company->id)->groupBy('location_id') as $locationId => $rows) {
                $locationStock[$locationId] = $rows->pluck('qty', 'product_id')->map(fn ($q) => (float) $q)->all();
            }
        }

        return $this->ok([
            'location_stock' => (object) $locationStock,
            'products' => $products, 'batches' => $batches, 'locations' => $locations,
            'my_location_id' => $request->user()->is_super_admin ? null : $request->user()->locationIdFor($company->id),
        ]);
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
        $this->assertRecordCompany($request, $stockVerification);

        return $this->ok(new StockVerificationResource($stockVerification->load(['items', 'location:id,name,type'])));
    }

    public function submit(Request $request, StockVerification $stockVerification): JsonResponse
    {
        $this->allow($request, 'stock_verifications.create');
        $this->assertRecordCompany($request, $stockVerification, writable: true, writableMessage: 'Switch to the count company to submit, approve, or reject.');

        return $this->ok(
            new StockVerificationResource($this->verifications->submit($stockVerification, $request->user()->id)),
            'Stock updated now. Head office / admin will check the count and mark it verified — you can keep billing.'
        );
    }

    /**
     * Quick fix while billing: the product is on the shelf but the system says it is out of stock.
     * The stock is set to the counted quantity immediately (no approval), head office / admin are told to
     * check it, and the bill can go ahead.
     */
    public function quick(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $user = $request->user();
        abort_unless($user->hasPermission('stock_verifications.create', $company->id) || $user->hasPermission('sales.create', $company->id), 403);

        $data = $request->validate([
            'product_id'       => ['required', 'uuid', \Illuminate\Validation\Rule::exists('products', 'id')->where('company_id', $company->id)],
            'counted_qty'      => ['required', 'numeric', 'min:0', 'max:9999999'],
            'location_id'      => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('locations', 'id')->where('company_id', $company->id)->where('is_active', true)],
            'notes'            => ['nullable', 'string', 'max:500'],
        ]);

        $v = $this->verifications->create($company->id, [
            'count_date'  => now()->toDateString(),
            'location_id' => $data['location_id'] ?? null,
            'is_quick'    => true,
            'notes'       => 'Quick correction while billing'.(filled($data['notes'] ?? null) ? ': '.$data['notes'] : '.'),
            'items'       => [['product_id' => $data['product_id'], 'counted_qty' => $data['counted_qty']]],
        ], $user->id);
        $v = $this->verifications->submit($v, $user->id);

        return $this->created(
            new StockVerificationResource($v->load(['items', 'location:id,name,type'])),
            'Stock corrected. Head office / admin will check it. You can bill now.'
        );
    }

    /** Head office / admin has checked a count whose stock was already updated. */
    public function verify(Request $request, StockVerification $stockVerification): JsonResponse
    {
        $this->allow($request, 'stock_verifications.approve');
        $this->assertRecordCompany($request, $stockVerification, writable: true, writableMessage: 'Switch to the count company to verify it.');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->ok(
            new StockVerificationResource($this->verifications->verify($stockVerification, $request->user()->id, $note)->load('location:id,name,type')),
            'Count verified.'
        );
    }

    public function approve(Request $request, StockVerification $stockVerification): JsonResponse
    {
        $this->allow($request, 'stock_verifications.approve');
        $this->assertRecordCompany($request, $stockVerification, writable: true, writableMessage: 'Switch to the count company to submit, approve, or reject.');

        return $this->ok(
            new StockVerificationResource($this->verifications->approve($stockVerification, $request->user()->id)),
            'Count approved — stock adjusted to match the physical count.'
        );
    }

    public function reject(RejectStockVerificationRequest $request, StockVerification $stockVerification): JsonResponse
    {
        $this->assertRecordCompany($request, $stockVerification, writable: true, writableMessage: 'Switch to the count company to submit, approve, or reject.');

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

}
