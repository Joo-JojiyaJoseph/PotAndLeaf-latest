<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        $company = $this->listCompany($request);
        $this->allow($request, 'receipts.view');

        return $this->ok(CustomerReceiptResource::collection(
            $this->receipts->list($company->id, $request->only(['customer_id', 'per_page']))
        ));
    }

    public function formData(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'receipts.create');

        $customers = Customer::forCompany($company->id)->orderBy('name')
            ->get(['id', 'name', 'outstanding'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'outstanding' => (float) $c->outstanding]);

        return $this->ok(['customers' => $customers]);
    }

    public function receivables(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'receipts.view');

        return $this->ok(['receivables' => $this->receipts->receivables($company->id, $request->query('customer_id') ?: null)]);
    }

    public function store(StoreCustomerReceiptRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $receipt = $this->receipts->record($company->id, $request->validated(), $request->user()->id);

        return $this->created(new CustomerReceiptResource($receipt), 'Receipt recorded.');
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
