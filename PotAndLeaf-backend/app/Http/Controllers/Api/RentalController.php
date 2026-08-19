<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rental\GenerateRentalInvoiceRequest;
use App\Http\Requests\Rental\ReturnRentalRequest;
use App\Http\Requests\Rental\StoreRentalRequest;
use App\Http\Resources\RentalResource;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalInvoice;
use App\Services\RentalNotificationService;
use App\Services\RentalService;
use App\Support\Api\ApiResponse;
use App\Support\Api\AssertsRecordCompany;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RentalController extends Controller
{
    use ApiResponse, AssertsRecordCompany, ResolvesFilterCompany;

    public function __construct(private readonly RentalService $rentals) {}

    public function index(Request $request): JsonResponse
    {
        $this->allow($request, 'rental.view');

        return $this->ok(RentalResource::collection($this->rentals->list($this->listCompanyId($request), $request->only(['search', 'status', 'per_page']))));
    }

    public function formData(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'rental.create');

        $customers = Customer::forCompany($company->id)->where('status', 'active')->orderBy('name')
            ->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]);
        $products = Product::forCompany($company->id)->orderBy('name')
            ->get(['id', 'sku', 'name', 'retail_price'])
            ->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'retail_price' => (float) $p->retail_price]);
        $locations = Location::forCompany($company->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')
            ->get(['id', 'name', 'is_default'])->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'is_default' => (bool) $l->is_default]);

        return $this->ok(['customers' => $customers, 'products' => $products, 'locations' => $locations]);
    }

    public function store(StoreRentalRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $rental = $this->rentals->create($company->id, $request->validated(), $request->user()->id);

        return $this->created(new RentalResource($rental), 'Rental saved as draft.');
    }

    public function show(Request $request, Rental $rental): JsonResponse
    {
        $this->allow($request, 'rental.view');
        $this->assertRecordCompany($request, $rental);

        return $this->ok(new RentalResource($rental->load(['items', 'invoices', 'customer:id,name,type', 'company:id,name,legal_name,gst_number,address,phone,state,state_code'])));
    }

    public function activate(Request $request, Rental $rental): JsonResponse
    {
        $this->allow($request, 'rental.activate');
        $this->assertRecordCompany($request, $rental, writable: true);

        return $this->ok(new RentalResource($this->rentals->activate($rental, $request->user()->id)), 'Rental activated — stock issued.');
    }

    public function returnItems(ReturnRentalRequest $request, Rental $rental): JsonResponse
    {
        $this->assertRecordCompany($request, $rental, writable: true);
        $returns = collect($request->validated()['returns'] ?? [])->mapWithKeys(fn ($r) => [$r['id'] => $r['qty']])->all();

        return $this->ok(new RentalResource($this->rentals->returnItems($rental, $returns, $request->user()->id)), 'Return recorded.');
    }

    public function settle(Request $request, Rental $rental): JsonResponse
    {
        $this->allow($request, 'rental.return');
        $this->assertRecordCompany($request, $rental, writable: true);

        $data = $request->validate([
            'return_date'      => ['nullable', 'date'],
            'damage_charge'    => ['nullable', 'numeric', 'min:0'],
            'lines'            => ['array'],
            'lines.*.id'       => ['required'],
            'lines.*.returned' => ['nullable', 'numeric', 'min:0'],
            'lines.*.damaged'  => ['nullable', 'numeric', 'min:0'],
            'lines.*.missing'  => ['nullable', 'numeric', 'min:0'],
        ]);

        $lines = collect($data['lines'] ?? [])->mapWithKeys(fn ($l) => [$l['id'] => [
            'returned' => $l['returned'] ?? 0, 'damaged' => $l['damaged'] ?? 0, 'missing' => $l['missing'] ?? 0,
        ]])->all();

        $rental = $this->rentals->settle($rental, $lines, $data['return_date'] ?? null, $data['damage_charge'] ?? null, $request->user()->id);

        return $this->ok(new RentalResource($rental), 'Rental settled — deposit adjusted and final invoice generated.');
    }

    public function destroy(Request $request, Rental $rental): JsonResponse
    {
        $this->allow($request, 'rental.delete');
        $this->assertRecordCompany($request, $rental, writable: true);
        $this->rentals->cancel($rental, $request->user()->id);

        return $this->message('Rental cancelled.');
    }

    public function generateInvoice(GenerateRentalInvoiceRequest $request, Rental $rental): JsonResponse
    {
        $this->assertRecordCompany($request, $rental, writable: true);
        $this->rentals->generateInvoice($this->company($request)->id, $rental, $request->validated(), $request->user()->id);

        return $this->ok(new RentalResource($rental->fresh()->load(['items', 'invoices', 'customer:id,name,type'])), 'Rental invoice generated.');
    }

    public function markInvoicePaid(Request $request, RentalInvoice $rentalInvoice): JsonResponse
    {
        $this->allow($request, 'rental.bill');
        $this->assertRecordCompany($request, $rentalInvoice, writable: true);
        $this->rentals->markInvoicePaid($rentalInvoice);

        return $this->message('Invoice marked paid.');
    }

    public function deleteInvoice(Request $request, RentalInvoice $rentalInvoice): JsonResponse
    {
        $this->allow($request, 'rental.bill');
        $this->assertRecordCompany($request, $rentalInvoice, writable: true);
        $this->rentals->deleteInvoice($rentalInvoice);

        return $this->message('Invoice removed.');
    }

    public function sendInvoiceWhatsapp(Request $request, RentalInvoice $rentalInvoice, RentalNotificationService $notifications): JsonResponse
    {
        $this->allow($request, 'rental.bill');
        $this->assertRecordCompany($request, $rentalInvoice, writable: true);

        $result = $notifications->sendInvoiceWhatsApp($rentalInvoice, requireAutoSetting: false);

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

}
