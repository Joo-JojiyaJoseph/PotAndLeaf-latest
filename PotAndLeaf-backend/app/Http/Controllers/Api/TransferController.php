<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transfer\ApproveTransferRequest;
use App\Http\Requests\Transfer\ReceiveTransferRequest;
use App\Http\Requests\Transfer\StoreTransferRequest;
use App\Http\Resources\StockTransferResource;
use App\Models\Location;
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
        $this->allow($request, 'transfers.view');
        $filters = $request->only(['search', 'status', 'per_page', 'from', 'to']);
        // A godown user sees only the transfers into or out of their own godown.
        if ($mine = $this->godownLocationId($request)) {
            $filters['location_id'] = $mine;
        }

        return $this->ok(StockTransferResource::collection($this->transfers->list($this->listCompanyId($request), $filters)));
    }

    /** For the "New transfer" screen: this company's branches and godowns, products and the stock each location holds. */
    public function formData(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'transfers.create');
        $user = $request->user();

        $products = Product::forCompany($company->id)->where('status', 'active')->orderBy('name')
            ->get(['id', 'sku', 'name', 'images'])
            ->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'image' => \App\Support\Media\MediaUrl::resolveMany($p->images ?? [])[0] ?? null]);

        $locations = Location::forCompany($company->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')
            ->get(['id', 'name', 'type', 'is_default'])
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'type' => $l->type, 'is_default' => (bool) $l->is_default]);

        return $this->ok([
            'products'         => $products,
            'locations'        => $locations,
            // What each location can give right now (less what is already promised on open transfers).
            'stock'            => $this->transfers->availableStock($company->id),
            'my_location_id'   => $user->is_super_admin ? null : $user->locationIdFor($company->id),
            'is_godown_user'   => $user->isGodownUser($company->id),
            'company'          => ['id' => $company->id, 'name' => $company->name, 'code' => $company->code],
        ]);
    }

    /**
     * For the "Request stock" screen: the stock the other branches and godowns of this company can give
     * to the chosen location.
     */
    public function requestData(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'transfers.create');
        $user = $request->user();

        $locations = Location::forCompany($company->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')
            ->get(['id', 'name', 'type', 'is_default']);
        $toId = $request->query('to_location_id');
        if (! filled($toId) || ! $locations->contains('id', $toId)) {
            $toId = $user->locationIdFor($company->id) ?: $locations->firstWhere('is_default', true)?->id ?: $locations->first()?->id;
        }
        $to = $locations->firstWhere('id', $toId);

        return $this->ok([
            'for_company'    => ['id' => $company->id, 'name' => $company->name],
            'locations'      => $locations->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'type' => $l->type, 'is_default' => (bool) $l->is_default]),
            'to_location_id' => $toId,
            'to_location'    => $to ? ['id' => $to->id, 'name' => $to->name, 'type' => $to->type] : null,
            'stock'          => $this->transfers->availableStock($company->id, $toId, $request->query('search')),
        ]);
    }

    /** A branch or godown asks the other locations of its company for stock (one request per giving location). */
    public function storeRequest(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->allow($request, 'transfers.create');
        $user = $request->user();
        $data = $request->validate([
            'to_location_id'          => ['required', 'uuid', \Illuminate\Validation\Rule::exists('locations', 'id')->where('company_id', $company->id)->where('is_active', true)],
            'transfer_date'           => ['required', 'date', 'before_or_equal:'.\App\Support\Validation\BusinessRules::today()],
            'notes'                   => ['nullable', 'string', 'max:1000'],
            'lines'                   => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.from_location_id' => ['required', 'uuid', \Illuminate\Validation\Rule::exists('locations', 'id')->where('company_id', $company->id)->where('is_active', true)],
            'lines.*.product_id'      => ['required', 'string'],
            'lines.*.qty'             => ['required', 'numeric', 'gt:0', 'max:9999999'],
        ], [
            'transfer_date.before_or_equal' => 'The request date cannot be in the future.',
            'lines.required'                => 'Add at least one product to request.',
            'lines.*.qty.gt'                => 'Each quantity must be more than 0.',
        ]);

        // A godown user can only ask for stock to be sent to their own godown.
        if ($user->isGodownUser($company->id) && (string) $data['to_location_id'] !== (string) $user->locationIdFor($company->id)) {
            abort(422, 'You can only request stock for your own godown.');
        }

        $made = $this->transfers->requestStock($company->id, $data['to_location_id'], $data, $user->id, (bool) $user->is_super_admin);
        $count = count($made);

        return $this->created(
            StockTransferResource::collection($made),
            $user->is_super_admin
                ? ($count === 1 ? 'Request created and approved. The sending location can now dispatch it.' : "{$count} requests created and approved (one per sending location).")
                : ($count === 1 ? 'Stock request sent. It waits for approval.' : "{$count} stock requests sent (one per sending location). They wait for approval.")
        );
    }

    /** Locations that have the requested products, so the approver can choose who sends. */
    public function sources(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->allow($request, 'transfers.approve');
        $this->canApprove($request, $stockTransfer);

        return $this->ok($this->transfers->sources($stockTransfer));
    }

    public function store(StoreTransferRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $user = $request->user();
        $data = $request->validated();
        unset($data['confirm']);
        $data['transfer_type'] = 'intra_company';

        // Whoever may approve transfers sends it straight to "ready to dispatch"; others wait for approval.
        $autoApprove = $user->is_super_admin ? $request->boolean('confirm') : $user->hasPermission('transfers.approve', $company->id);

        $transfer = $this->transfers->create($company->id, $data, $user->id, $autoApprove);

        return $this->created(
            new StockTransferResource($transfer),
            $autoApprove ? 'Transfer saved as draft.' : 'Transfer request submitted for approval.',
        );
    }

    public function approve(ApproveTransferRequest $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->canApprove($request, $stockTransfer);
        $from = $request->input('from_location_id');

        return $this->ok(
            new StockTransferResource($this->transfers->approve(
                $stockTransfer,
                $request->validated()['approvals'] ?? [],
                $request->user()->id,
                null,
                null,
                filled($from) ? $from : null,
            )->load(['items', 'fromLocation:id,name,type', 'toLocation:id,name,type'])),
            'Approved. The sending location can now dispatch the stock.',
        );
    }

    public function reject(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->allow($request, 'transfers.approve');
        $this->canApprove($request, $stockTransfer);
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
        $this->godownScope($request, $stockTransfer);

        return $this->ok(new StockTransferResource($stockTransfer->load([
            'items.batch.purchase:id,purchase_no',
            'fromCompany:id,name', 'toCompany:id,name', 'fromLocation:id,name,type', 'toLocation:id,name,type',
            'redirectedFromCompany:id,name', 'requester:id,name', 'items.receivedBatch:id,batch_no,barcode',
        ])));
    }

    public function dispatchTransfer(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->allow($request, 'transfers.dispatch');
        $this->sameSourceCompany($request, $stockTransfer);
        $this->godownScope($request, $stockTransfer);

        return $this->ok(new StockTransferResource($this->transfers->dispatch($stockTransfer, $request->user()->id)), 'Transfer dispatched — stock in transit.');
    }

    public function receive(ReceiveTransferRequest $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->sameDestinationCompany($request, $stockTransfer);
        $this->godownScope($request, $stockTransfer);

        $receipts = collect($request->validated()['receipts'] ?? [])->mapWithKeys(fn ($r) => [$r['id'] => $r['received_qty']])->all();

        return $this->ok(new StockTransferResource($this->transfers->receive($stockTransfer, $receipts, $request->user()->id)), 'Transfer received.');
    }

    public function destroy(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->allow($request, 'transfers.delete');
        // The company that asked may withdraw its own request before approval.
        $ownRequest = $stockTransfer->status === 'requested' && ! $stockTransfer->isIntraCompany()
            && (string) $stockTransfer->to_company_id === (string) $this->company($request)->id;
        if (! $ownRequest) {
            $this->sameSourceCompany($request, $stockTransfer);
        }
        $this->transfers->cancel($stockTransfer, $request->user()->id);

        return $this->message('Transfer cancelled.');
    }

    /** The location a godown user works at (null for everyone else). */
    private function godownLocationId(Request $request): ?string
    {
        $user = $request->user();
        $companyId = $this->company($request)->id;

        return $user->isGodownUser($companyId) ? $user->locationIdFor($companyId) : null;
    }

    /** A godown user can only open transfers into or out of their own godown. */
    private function godownScope(Request $request, StockTransfer $transfer): void
    {
        $mine = $this->godownLocationId($request);
        if ($mine !== null) {
            abort_unless(
                (string) $transfer->from_location_id === $mine || (string) $transfer->to_location_id === $mine,
                404,
                'This transfer is not for your godown.',
            );
        }
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

    /**
     * Approving: the admin, or the company that will GIVE the stock. The company
     * that asked cannot approve its own request.
     */
    private function canApprove(Request $request, StockTransfer $transfer): void
    {
        if ($request->user()->is_super_admin) {
            return;
        }
        if ($transfer->isIntraCompany()) {
            $this->sameSourceCompany($request, $transfer);

            return;
        }
        $headerId = (string) $this->company($request)->id;
        if ((string) $transfer->to_company_id === $headerId) {
            abort(403, 'Your request waits for the admin to approve it.');
        }
        if ((string) $transfer->company_id !== $headerId) {
            abort(404);
        }
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
