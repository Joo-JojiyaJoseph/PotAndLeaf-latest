<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Receipt\ApplyCustomerAdvanceRequest;
use App\Http\Requests\Receipt\StoreCustomerReceiptRequest;
use App\Http\Resources\CustomerReceiptResource;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Services\ReceiptService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerReceiptController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(private readonly ReceiptService $receipts) {}

    public function index(Request $request): JsonResponse
    {
        $this->allow($request, 'receipts.view');

        return $this->ok(CustomerReceiptResource::collection(
            $this->receipts->list($this->listCompanyId($request), $request->only(['customer_id', 'per_page']))
        ));
    }

    public function formData(Request $request): JsonResponse
    {
        $this->allow($request, 'receipts.create');
        $companyId = $this->listCompanyId($request);

        $customers = Customer::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->orderBy('name')
            ->get(['id', 'name', 'outstanding', 'advance_balance', 'company_id'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'outstanding' => (float) $c->outstanding,
                'advance_balance' => (float) $c->advance_balance,
                'company_id' => $c->company_id,
            ]);

        return $this->ok(['customers' => $customers]);
    }

    public function receivables(Request $request): JsonResponse
    {
        $this->allow($request, 'receipts.view');

        return $this->ok(['receivables' => $this->receipts->receivables($this->listCompanyId($request), $request->query('customer_id') ?: null)]);
    }

    public function store(StoreCustomerReceiptRequest $request): JsonResponse
    {
        $receipt = $this->receipts->record($request->writeCompanyId(), $request->validated(), $request->user()->id);

        return $this->created(new CustomerReceiptResource($receipt), 'Receipt recorded.');
    }

    public function applyAdvance(ApplyCustomerAdvanceRequest $request): JsonResponse
    {
        $receipt = $this->receipts->applyAdvance($request->writeCompanyId(), $request->validated(), $request->user()->id);

        return $this->created(new CustomerReceiptResource($receipt), 'Customer advance applied.');
    }

    public function destroy(Request $request, CustomerReceipt $customerReceipt): JsonResponse
    {
        $this->allow($request, 'receipts.delete');
        abort_unless((string) $customerReceipt->company_id === (string) $this->company($request)->id, 404);
        $this->receipts->delete($customerReceipt);

        return $this->message('Receipt voided.');
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
