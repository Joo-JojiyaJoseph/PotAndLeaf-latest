<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sale\RejectSaleCancellationRequest;
use App\Http\Requests\Sale\RequestSaleCancellationRequest;
use App\Http\Requests\Sale\StoreSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Services\SaleNotificationService;
use App\Services\SaleService;
use App\Support\Api\ApiResponse;
use App\Support\Api\AssertsRecordCompany;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    use ApiResponse, AssertsRecordCompany, ResolvesFilterCompany;

    public function __construct(private readonly SaleService $sales) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('sales.view', $this->company($request)->id), 403);

        $filters = $request->only(['search', 'status', 'per_page', 'from', 'to', 'customer_id', 'payment_mode']);
        // Salesmen see only the bills they made.
        if ($request->user()->seesOnlyOwn('sales', $this->company($request)->id)) {
            $filters['created_by'] = $request->user()->id;
        }

        return $this->ok(SaleResource::collection($this->sales->list($this->listCompanyId($request), $filters)));
    }

    public function formData(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'sales.create');

        $customers = Customer::forCompany($company->id)->where('status', 'active')
            ->when($request->user()->seesOnlyOwn('customers', $company->id), fn ($q) => $q->where('created_by', $request->user()->id))
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'loyalty_points'])
            ->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'type' => $c->type?->value,
                'loyalty_points' => (int) $c->loyalty_points,
            ]);

        $settings = app(\App\Services\SettingsService::class)->all($company->id);

        $products = Product::forCompany($company->id)->where('status', 'active')->orderBy('name')
            ->get(['id', 'sku', 'name', 'hsn_code', 'gst_rate', 'current_stock', 'retail_price', 'wholesale_price', 'dealer_price', 'mrp'])
            ->map(fn ($p) => [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'hsn_code' => $p->hsn_code,
                'gst_rate' => (float) $p->gst_rate, 'current_stock' => (float) $p->current_stock,
                'retail_price' => (float) $p->retail_price, 'wholesale_price' => (float) $p->wholesale_price,
                'dealer_price' => (float) $p->dealer_price, 'mrp' => (float) $p->mrp,
            ]);

        // Batches with stock, oldest first, so a line can be sold from a chosen batch.
        $seeCost = $request->user()->hasPermission('products.view_cost', $company->id);
        $batches = \App\Models\ProductBatch::query()
            ->where('company_id', $company->id)
            ->where('remaining_qty', '>', 0)
            ->whereIn('product_id', $products->pluck('id'))
            ->orderBy('received_at')->orderBy('created_at')
            ->get(['id', 'product_id', 'batch_no', 'barcode', 'remaining_qty', 'cost_price', 'received_at'])
            ->map(fn ($b) => array_filter([
                'id' => $b->id, 'product_id' => $b->product_id, 'batch_no' => $b->batch_no, 'barcode' => $b->barcode,
                'remaining_qty' => (float) $b->remaining_qty,
                'received_at' => optional($b->received_at)->toDateString(),
                'cost_price' => $seeCost ? round((float) $b->cost_price, 2) : null,
            ], fn ($v) => $v !== null))
            ->values();

        $locations = Location::forCompany($company->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')
            ->get(['id', 'name', 'is_default'])
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'is_default' => (bool) $l->is_default]);

        return $this->ok([
            'customers' => $customers,
            'products'  => $products,
            'batches'   => $batches,
            'locations' => $locations,
            'settings'  => [
                'loyalty_earn_rupees'        => (float) $settings['loyalty_earn_rupees'],
                'loyalty_earn_points'        => (int) $settings['loyalty_earn_points'],
                'loyalty_redeem_rupees'      => (float) $settings['loyalty_redeem_rupees'],
                'loyalty_redeem_cap_percent' => (float) $settings['loyalty_redeem_cap_percent'],
                'discount_ceiling_percent'   => (float) $settings['discount_ceiling_percent'],
                'sale_cancel_requires_approval'=> $settings['sale_cancel_requires_approval'],
            ],
        ]);
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $sale = $this->sales->create($company->id, $request->validated(), $request->user()->id);

        return $this->created(new SaleResource($sale), 'Sale saved as draft.');
    }

    public function show(Request $request, Sale $sale): JsonResponse
    {
        $this->allow($request, 'sales.view');
        $this->assertRecordCompany($request, $sale);
        $this->assertOwnSale($request, $sale);

        return $this->ok(new SaleResource($sale->load([
            'items.batch:id,batch_no', 'customer:id,name,type', 'createdBy:id,name',
            'company:id,name,legal_name,gst_number,address,phone,email,state,state_code',
            'cancelRequestedBy:id,name', 'cancelReviewedBy:id,name',
        ])));
    }

    public function confirm(Request $request, Sale $sale): JsonResponse
    {
        $this->allow($request, 'sales.confirm');
        $this->assertRecordCompany($request, $sale, writable: true);

        $confirmed = $this->sales->confirm($sale, $request->user()->id);
        $message = $confirmed->isProforma()
            ? 'Proforma issued — no stock movement.'
            : 'Sale confirmed — stock updated.';

        return $this->ok(new SaleResource($confirmed), $message);
    }

    public function destroy(Request $request, Sale $sale): JsonResponse
    {
        $this->allow($request, 'sales.delete');
        $this->assertRecordCompany($request, $sale, writable: true);
        $this->sales->cancel($sale, $request->user()->id);

        return $this->message('Sale cancelled.');
    }

    public function requestCancellation(RequestSaleCancellationRequest $request, Sale $sale): JsonResponse
    {
        $this->assertRecordCompany($request, $sale, writable: true);

        return $this->ok(
            new SaleResource($this->sales->requestCancellation($sale, $request->validated('reason'), $request->user()->id)),
            'Cancellation request submitted for HO approval.',
        );
    }

    public function approveCancellation(Request $request, Sale $sale): JsonResponse
    {
        $this->allow($request, 'sales.cancel_approve');
        $this->assertRecordCompany($request, $sale, writable: true);

        return $this->ok(
            new SaleResource($this->sales->approveCancellation($sale, $request->user()->id)),
            'Cancellation approved — sale reversed.',
        );
    }

    public function rejectCancellation(RejectSaleCancellationRequest $request, Sale $sale): JsonResponse
    {
        $this->assertRecordCompany($request, $sale, writable: true);

        return $this->ok(
            new SaleResource($this->sales->rejectCancellation($sale, $request->validated('reason'), $request->user()->id)),
            'Cancellation request rejected.',
        );
    }

    public function convertProforma(Request $request, Sale $sale): JsonResponse
    {
        $this->allow($request, 'sales.confirm');
        $this->assertRecordCompany($request, $sale, writable: true);

        return $this->ok(
            new SaleResource($this->sales->convertProforma($sale, $request->user()->id)),
            'Proforma converted to tax invoice draft — confirm to post stock.',
        );
    }

    public function sendWhatsapp(Request $request, Sale $sale, SaleNotificationService $notifications): JsonResponse
    {
        $this->allow($request, 'sales.whatsapp');
        $this->assertRecordCompany($request, $sale, writable: true);

        $result = $notifications->sendInvoiceWhatsApp($sale);

        return $result['success']
            ? $this->ok(['provider' => $result['provider'] ?? null], $result['message'])
            : $this->message($result['message'], 422);
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    /** Salesmen may open only the bills they made. */
    private function assertOwnSale(Request $request, Sale $sale): void
    {
        $user = $request->user();
        abort_if($user->seesOnlyOwn('sales', $sale->company_id) && (int) $sale->created_by !== (int) $user->id, 403, 'You can only see the bills you made.');
    }
}
