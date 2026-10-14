<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transfer\ApproveTransferRequest;
use App\Http\Requests\Transfer\ReceiveTransferRequest;
use App\Http\Requests\Transfer\StoreTransferRequest;
use App\Http\Resources\StockTransferResource;
use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
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
        $this->allow($request, 'transfers.view');

        return $this->ok(StockTransferResource::collection($this->transfers->list($this->listCompanyId($request), $request->only(['search', 'status', 'per_page']))));
    }

    public function formData(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'transfers.create');

        $source = $this->resolveSourceCompany($request, $company);

        $destinations = Company::active()->where('id', '!=', $source->id)->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code]);

        $sourceCompanies = Company::active()->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code]);

        $productRows = Product::forCompany($source->id)->where('status', 'active')->orderBy('name')->get(['id', 'sku', 'name', 'current_stock']);
        $reserved = $this->draftTransferQtyByProduct($source->id, $productRows->pluck('id'));
        $products = $productRows->map(function ($p) use ($reserved) {
            $draft = (float) ($reserved[$p->id] ?? 0);

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'current_stock' => (float) $p->current_stock,
                'draft_transfer_qty' => $draft,
                'available_qty' => round(max(0, (float) $p->current_stock - $draft), 3),
            ];
        });

        $locations = Location::forCompany($source->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')
            ->get(['id', 'name', 'type', 'is_default'])
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'type' => $l->type, 'is_default' => (bool) $l->is_default]);

        return $this->ok([
            'companies' => $destinations,
            'source_companies' => $sourceCompanies,
            'products' => $products,
            'locations' => $locations,
            'from_company' => ['id' => $source->id, 'name' => $source->name, 'code' => $source->code],
        ]);
    }

    public function store(StoreTransferRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $user = $request->user();
        $data = $request->validated();
        $sourceId = $data['from_company_id'] ?? $company->id;
        unset($data['confirm'], $data['from_company_id']);

        $pullingFromOther = (string) $sourceId !== (string) $company->id;
        if (! $user->is_super_admin && $pullingFromOther) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'from_company_id' => 'Company users request a transfer from their own company. An admin assigns the other company.',
            ]);
        }
        if (! $user->is_super_admin) {
            $sourceId = $company->id;
            unset($data['from_company_id']);
        }
        $isIntra = ($data['transfer_type'] ?? 'inter_company') === 'intra_company';
        // Inter-company requests from company users always wait for admin approval.
        // Location transfers keep the previous approve-permission shortcut.
        $autoApprove = $isIntra
            ? ($user->is_super_admin ? $request->boolean('confirm') : $user->hasPermission('transfers.approve', $company->id))
            : ($user->is_super_admin && $request->boolean('confirm') && filled($data['to_company_id'] ?? null));

        $transfer = $this->transfers->create($sourceId, $data, $user->id, $autoApprove);

        return $this->created(
            new StockTransferResource($transfer),
            $autoApprove ? 'Transfer saved as draft.' : 'Transfer request submitted for approval.',
        );
    }

    public function approve(ApproveTransferRequest $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->sameCompanyOrDestination($request, $stockTransfer);

        return $this->ok(
            new StockTransferResource($this->transfers->approve(
                $stockTransfer,
                $request->validated()['approvals'] ?? [],
                $request->user()->id,
                $request->validated()['to_company_id'] ?? null,
            )),
            'Transfer approved.',
        );
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

        return $this->ok(new StockTransferResource($stockTransfer->load([
            'items.batch.purchase:id,purchase_no',
            'fromCompany:id,name', 'toCompany:id,name', 'fromLocation:id,name', 'toLocation:id,name',
            'redirectedFromCompany:id,name',
        ])));
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

    /** Any active company may be the stock source; membership is not required to see it. */
    private function resolveSourceCompany(Request $request, Company $current): Company
    {
        $sourceId = $request->query('source_company_id');
        if (! filled($sourceId) || (string) $sourceId === (string) $current->id) {
            return $current;
        }

        $source = Company::active()->whereKey($sourceId)->first();
        abort_unless($source, 422, 'Source company not found.');

        return $source;
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    private function sameCompanyOrDestination(Request $request, StockTransfer $transfer): void
    {
        if ($transfer->isIntraCompany()) {
            $this->sameSourceCompany($request, $transfer);

            return;
        }

        $headerId = (string) $this->company($request)->id;
        $sourceId = (string) $transfer->company_id;
        $destId = (string) $transfer->to_company_id;

        if ($sourceId === $headerId || $destId === $headerId) {
            return;
        }

        $queryId = $request->query('company_id');
        if (filled($queryId) && ($sourceId === (string) $queryId || $destId === (string) $queryId)) {
            return;
        }

        if ($request->user()->is_super_admin) {
            return;
        }

        abort(404);
    }

    /** @param  \Illuminate\Support\Collection<int, string>  $productIds */
    private function draftTransferQtyByProduct(int|string $companyId, $productIds): array
    {
        if ($productIds->isEmpty()) {
            return [];
        }

        return StockTransferItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('stockTransfer', fn ($q) => $q->where('company_id', $companyId)->whereIn('status', ['draft', 'requested']))
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => round($rows->sum(fn ($row) => $row->dispatchQty()), 3))
            ->all();
    }

    private function sameSourceCompany(Request $request, StockTransfer $transfer): void
    {
        $headerId = (string) $this->company($request)->id;
        $sourceId = (string) $transfer->company_id;

        if ($sourceId === $headerId) {
            return;
        }

        if ($request->user()->is_super_admin) {
            $queryId = $request->query('company_id');
            if ($sourceId === $headerId || (filled($queryId) && $sourceId === (string) $queryId)) {
                return;
            }
        }

        abort(404, 'Switch to the source company to modify this transfer.');
    }

    private function sameDestinationCompany(Request $request, StockTransfer $transfer): void
    {
        if ($transfer->isIntraCompany()) {
            $this->sameSourceCompany($request, $transfer);

            return;
        }

        $headerId = (string) $this->company($request)->id;
        $destId = (string) $transfer->to_company_id;

        if ($destId === $headerId) {
            return;
        }

        // Super-admin may receive from either shop (the UI often keeps the
        // source company in X-Company-Id while viewing an incoming transfer).
        if ($request->user()->is_super_admin) {
            return;
        }

        abort(404, 'Switch to the destination company to receive this transfer.');
    }
}
