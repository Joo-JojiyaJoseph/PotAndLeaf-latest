<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transfer\ReceiveTransferRequest;
use App\Http\Requests\Transfer\StoreTransferRequest;
use App\Http\Resources\StockTransferResource;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Services\TransferService;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function __construct(private readonly TransferService $transfers) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'transfers.view');

        return $this->ok(StockTransferResource::collection($this->transfers->list($company->id, $request->only(['search', 'status', 'per_page']))));
    }

    public function formData(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'transfers.create');

        $user = $request->user();
        if ($user->is_super_admin) {
            $companies = Company::active()->where('id', '!=', $company->id)->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code]);
        } else {
            $companies = $user->companies()->where('companies.id', '!=', $company->id)->where('is_active', true)
                ->orderBy('companies.name')
                ->get(['companies.id', 'companies.name', 'companies.code'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code]);
        }

        $products = Product::forCompany($company->id)->orderBy('name')->get(['id', 'sku', 'name', 'current_stock'])
            ->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'current_stock' => (float) $p->current_stock]);

        return $this->ok(['companies' => $companies, 'products' => $products, 'from_company' => ['id' => $company->id, 'name' => $company->name]]);
    }

    public function store(StoreTransferRequest $request): JsonResponse
    {
        $company = $this->company($request);
        // Users who can approve create ready-to-dispatch drafts; everyone else
        // creates a request that HO must approve first.
        $autoApprove = $request->user()->hasPermission('transfers.approve', $company->id);
        $transfer = $this->transfers->create($company->id, $request->validated(), $request->user()->id, $autoApprove);

        return $this->created(
            new StockTransferResource($transfer),
            $autoApprove ? 'Transfer saved as draft.' : 'Transfer request submitted for approval.',
        );
    }

    public function approve(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->allow($request, 'transfers.approve');
        $this->sameCompanyOrDestination($request, $stockTransfer);

        return $this->ok(new StockTransferResource($this->transfers->approve($stockTransfer, $request->user()->id)), 'Transfer approved.');
    }

    public function reject(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->allow($request, 'transfers.approve');
        $this->sameCompanyOrDestination($request, $stockTransfer);
        $reason = $request->input('reason');

        return $this->ok(new StockTransferResource($this->transfers->reject($stockTransfer, $reason, $request->user()->id)), 'Transfer rejected.');
    }

    public function redirect(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->allow($request, 'transfers.approve');
        $this->sameSourceCompany($request, $stockTransfer);
        $newTo = (int) $request->input('to_company_id');

        return $this->ok(new StockTransferResource($this->transfers->redirect($stockTransfer, $newTo, $request->user()->id)), 'In-transit stock redirected.');
    }

    public function show(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->allow($request, 'transfers.view');
        $this->sameCompanyOrDestination($request, $stockTransfer);

        return $this->ok(new StockTransferResource($stockTransfer->load(['items.batch.purchase:id,purchase_no', 'fromCompany:id,name', 'toCompany:id,name'])));
    }

    public function dispatchTransfer(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->allow($request, 'transfers.dispatch');
        $this->sameSourceCompany($request, $stockTransfer);

        return $this->ok(new StockTransferResource($this->transfers->dispatch($stockTransfer, $request->user()->id)), 'Transfer dispatched — stock in transit.');
    }

    public function receive(ReceiveTransferRequest $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->sameDestinationCompany($request, $stockTransfer);

        $receipts = collect($request->validated()['receipts'] ?? [])->mapWithKeys(fn ($r) => [$r['id'] => $r['received_qty']])->all();

        return $this->ok(new StockTransferResource($this->transfers->receive($stockTransfer, $receipts, $request->user()->id)), 'Transfer received.');
    }

    public function destroy(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->allow($request, 'transfers.delete');
        $this->sameSourceCompany($request, $stockTransfer);
        $this->transfers->cancel($stockTransfer, $request->user()->id);

        return $this->message('Transfer cancelled.');
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    private function sameCompanyOrDestination(Request $request, StockTransfer $transfer): void
    {
        $companyId = (string) $this->company($request)->id;
        abort_unless(
            (string) $transfer->company_id === $companyId || (string) $transfer->to_company_id === $companyId,
            404
        );
    }

    private function sameSourceCompany(Request $request, StockTransfer $transfer): void
    {
        abort_unless((string) $transfer->company_id === (string) $this->company($request)->id, 404);
    }

    private function sameDestinationCompany(Request $request, StockTransfer $transfer): void
    {
        abort_unless((string) $transfer->to_company_id === (string) $this->company($request)->id, 404);
    }
}
