<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StoreSupplierPaymentRequest;
use App\Http\Resources\SupplierPaymentResource;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\PaymentService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierPaymentController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'payments.view');

        return $this->ok(SupplierPaymentResource::collection(
            $this->payments->list($company->id, $request->only(['supplier_id', 'per_page']))
        ));
    }

    /** Suppliers (with outstanding) for the record-payment form. */
    public function formData(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'payments.create');

        $suppliers = Supplier::forCompany($company->id)->orderBy('name')
            ->get(['id', 'name', 'outstanding'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'outstanding' => (float) $s->outstanding]);

        return $this->ok(['suppliers' => $suppliers]);
    }

    /** Confirmed purchases with paid / balance / due-date / status. */
    public function payables(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'payments.view');

        return $this->ok(['payables' => $this->payments->payables($company->id, $request->query('supplier_id'))]);
    }

    public function store(StoreSupplierPaymentRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $payment = $this->payments->record($company->id, $request->validated(), $request->user()->id);

        return $this->created(new SupplierPaymentResource($payment), 'Payment recorded.');
    }

    public function destroy(Request $request, SupplierPayment $supplierPayment): JsonResponse
    {
        $this->allow($request, 'payments.delete');
        abort_unless((string) $supplierPayment->company_id === (string) $this->company($request)->id, 404);
        $this->payments->delete($supplierPayment, $request->user()->id);

        return $this->message('Payment voided.');
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
