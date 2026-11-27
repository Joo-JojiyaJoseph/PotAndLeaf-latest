<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly LocationStockService $locationStock,
        private readonly SupervisorCommissionService $supervisorCommission,
        private readonly ActivityLogService $activity,
    ) {}

    public function list(int|string|null $companyId, array $filters): LengthAwarePaginator
    {
        return StockTransfer::query()
            ->when($companyId !== null, fn ($q) => $q->where(fn ($inner) => $inner->where('company_id', $companyId)->orWhere('to_company_id', $companyId)))
            ->with(['fromCompany:id,name', 'toCompany:id,name', 'fromLocation:id,name,type', 'toLocation:id,name,type'])
            ->withCount('items')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['location_id'] ?? null), fn ($q) => $q->where(fn ($w) => $w->where('from_location_id', $filters['location_id'])->orWhere('to_location_id', $filters['location_id'])))
            ->listFilters($filters, 'transfer_date', ['transfer_no'])
            ->orderByDesc('transfer_date')->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 15), 100))
            ->withQueryString();
    }

    public function find(int|string $companyId, string $id): ?StockTransfer
    {
        return StockTransfer::query()
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhere('to_company_id', $companyId))
            ->with(['items', 'fromCompany:id,name', 'toCompany:id,name', 'fromLocation:id,name', 'toLocation:id,name'])
            ->whereKey($id)->first();
    }

    /**
     * Create a transfer between two locations (branch or godown) of the same company.
     * Allowed: godown to branch, branch to godown, branch to branch. Never company to company,
     * and never godown to godown.
     */
    public function create(int|string $companyId, array $data, ?int $userId = null, bool $autoApprove = true): StockTransfer
    {
        if (($data['transfer_type'] ?? 'intra_company') === 'inter_company') {
            throw ValidationException::withMessages(['transfer_type' => 'Stock can only move between the branches and godowns of one company, not between companies.']);
        }

        $status = $autoApprove ? 'draft' : 'requested';
        $transfer = $this->createIntraCompany($companyId, $data, $status);
        $this->logTransfer($companyId, $userId, 'create', $transfer, 'Transfer created');

        return $transfer;
    }

    /** Active location of this company, or a clear validation error. */
    private function activeLocation(int|string $companyId, ?string $locationId, string $field): Location
    {
        $location = $locationId
            ? Location::forCompany($companyId)->whereKey($locationId)->where('is_active', true)->first()
            : null;
        if (! $location) {
            throw ValidationException::withMessages([$field => 'Select a valid active branch or godown of this company.']);
        }

        return $location;
    }

    /** godown to branch, branch to godown and branch to branch are allowed; godown to godown is not. */
    private function assertTransferPair(Location $from, Location $to, string $field = 'to_location_id'): void
    {
        if ((string) $from->id === (string) $to->id) {
            throw ValidationException::withMessages([$field => 'The destination must differ from the source.']);
        }
        if ($from->type === 'godown' && $to->type === 'godown') {
            throw ValidationException::withMessages([$field => 'Stock cannot move from one godown to another. Send it to a branch first.']);
        }
    }

    /** Qty promised on open (requested / approved) transfers, per product, leaving one location. */
    private function openLocationQty(string $fromLocationId, array $productIds, ?string $exceptTransferId = null): array
    {
        if ($productIds === []) {
            return [];
        }

        return StockTransferItem::query()
            ->whereIn('product_id', $productIds)
            ->when($exceptTransferId, fn ($q) => $q->where('stock_transfer_id', '!=', $exceptTransferId))
            ->whereHas('stockTransfer', fn ($q) => $q->where('from_location_id', $fromLocationId)->whereIn('status', ['draft', 'requested']))
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => round($rows->sum(fn ($row) => $row->dispatchQty()), 3))
            ->all();
    }

    /**
     * Stock the other branches and godowns of the company can give: for each other location,
     * each active product it holds, less what is already promised on open transfers.
     *
     * @return list<array<string, mixed>>
     */
    public function availableStock(int|string $companyId, ?string $excludeLocationId = null, ?string $search = null): array
    {
        $needle = filled($search) ? mb_strtolower(trim($search)) : null;
        $rows = $this->locationStock->balances($companyId)
            ->filter(fn ($r) => (float) $r['qty'] > 0 && (string) $r['location_id'] !== (string) $excludeLocationId)
            ->when($needle, fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower($r['product_name'].' '.$r['sku']), $needle)));
        if ($rows->isEmpty()) {
            return [];
        }

        $products = Product::query()->whereIn('id', $rows->pluck('product_id')->unique())->where('status', 'active')
            ->with(['unit:id,name,short_name', 'category:id,name'])->get()->keyBy('id');
        $reserved = [];
        foreach ($rows->pluck('location_id')->unique() as $locationId) {
            $reserved[$locationId] = $this->openLocationQty($locationId, $rows->where('location_id', $locationId)->pluck('product_id')->all());
        }

        return $rows->map(function ($r) use ($products, $reserved) {
            $p = $products[$r['product_id']] ?? null;
            if (! $p) {
                return null;
            }

            return [
                'location_id'   => $r['location_id'],
                'location_name' => $r['location_name'],
                'location_type' => $r['location_type'],
                'product_id'    => $p->id,
                'name'          => $p->name,
                'sku'           => $p->sku,
                'category'      => $p->category?->name,
                'unit'          => $p->unit?->short_name ?: $p->unit?->name,
                'in_stock'      => (float) $r['qty'],
                'available'     => round(max(0, (float) $r['qty'] - (float) ($reserved[$r['location_id']][$p->id] ?? 0)), 3),
            ];
        })->filter(fn ($r) => $r && $r['available'] > 0)
            ->sortBy([['name', 'asc'], ['location_name', 'asc']])->values()->all();
    }

    /**
     * A branch or godown asks another location of the company for stock. One request is made
     * for each location the stock comes from. Requests wait for approval unless $autoApprove.
     *
     * @param  array{transfer_date: string, notes?: ?string, lines: list<array{from_location_id: string, product_id: string, qty: float|string}>}  $data
     * @return list<StockTransfer>
     */
    public function requestStock(int|string $companyId, string $toLocationId, array $data, ?int $userId = null, bool $autoApprove = false): array
    {
        $to = $this->activeLocation($companyId, $toLocationId, 'to_location_id');
        $lines = collect($data['lines'] ?? []);
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Add at least one product to request.']);
        }

        $products = Product::forCompany($companyId)->whereIn('id', $lines->pluck('product_id'))->get()->keyBy('id');
        $fromIds = $lines->pluck('from_location_id')->unique();
        $froms = [];
        foreach ($fromIds as $fid) {
            $froms[$fid] = $this->activeLocation($companyId, $fid, 'lines');
            $this->assertTransferPair($froms[$fid], $to, 'lines');
        }

        foreach ($lines->groupBy(fn ($l) => $l['from_location_id'].'|'.$l['product_id']) as $rows) {
            $first = $rows->first();
            $product = $products[$first['product_id']] ?? null;
            if (! $product) {
                throw ValidationException::withMessages(['lines' => 'A selected product was not found. Refresh and try again.']);
            }
            $want = round((float) $rows->sum(fn ($r) => (float) $r['qty']), 3);
            if ($want <= 0) {
                throw ValidationException::withMessages(['lines' => "Enter a quantity for {$product->name}."]);
            }
            $reserved = $this->openLocationQty($first['from_location_id'], [$product->id]);
            $free = round(max(0, $this->locationStock->available($first['from_location_id'], $product->id) - (float) ($reserved[$product->id] ?? 0)), 3);
            if ($want > $free + 0.0001) {
                $where = $froms[$first['from_location_id']]->name;
                throw ValidationException::withMessages(['lines' => "Only {$free} of {$product->name} is free at {$where}; you asked for {$want}."]);
            }
        }

        return DB::transaction(function () use ($lines, $products, $companyId, $to, $data, $userId, $autoApprove) {
            $made = [];
            foreach ($lines->groupBy('from_location_id') as $fromId => $rows) {
                $transfer = StockTransfer::create([
                    'company_id'       => $companyId,
                    'to_company_id'    => null,
                    'transfer_type'    => 'intra_company',
                    'from_location_id' => $fromId,
                    'to_location_id'   => $to->id,
                    'transfer_no'      => $this->nextTransferNo($companyId),
                    'transfer_date'    => $data['transfer_date'],
                    'status'           => $autoApprove ? 'draft' : 'requested',
                    'approved_at'      => $autoApprove ? now() : null,
                    'notes'            => $data['notes'] ?? null,
                    'requested_by'     => $userId,
                ]);
                $transfer->items()->createMany($rows->groupBy('product_id')->map(fn ($r, $pid) => [
                    'product_id'   => $pid,
                    'product_name' => $products[$pid]->name,
                    'qty'          => round((float) $r->sum(fn ($x) => (float) $x['qty']), 3),
                    'received_qty' => 0,
                ])->values()->all());
                $this->logTransfer($companyId, $userId, 'create', $transfer, 'Stock requested by '.$to->name);
                $made[] = $transfer->load(['items', 'fromLocation:id,name,type', 'toLocation:id,name,type']);
            }

            return $made;
        });
    }

    /**
     * Other locations that could send a request instead: for each, how much of the
     * requested products it has free.
     */
    public function sources(StockTransfer $transfer): array
    {
        $transfer->loadMissing('items');
        $rows = [];
        $to = Location::find($transfer->to_location_id);
        foreach (Location::forCompany($transfer->company_id)->where('is_active', true)->where('id', '!=', $transfer->to_location_id)->orderBy('name')->get(['id', 'name', 'type']) as $loc) {
            if ($to && $loc->type === 'godown' && $to->type === 'godown') {
                continue;
            }
            $reserved = $this->openLocationQty($loc->id, $transfer->items->pluck('product_id')->filter()->all(), $transfer->id);
            $lines = [];
            $enough = true;
            foreach ($transfer->items as $item) {
                $free = round(max(0, $this->locationStock->available($loc->id, (string) $item->product_id) - (float) ($reserved[$item->product_id] ?? 0)), 3);
                $enough = $enough && $free + 0.0001 >= (float) $item->qty;
                $lines[] = ['item_id' => $item->id, 'product_name' => $item->product_name, 'requested' => (float) $item->qty, 'available' => $free, 'found' => true];
            }
            $rows[] = [
                'location_id' => $loc->id, 'location_name' => $loc->name, 'location_type' => $loc->type,
                'is_current' => (string) $loc->id === (string) $transfer->from_location_id, 'enough' => $enough, 'lines' => $lines,
            ];
        }
        usort($rows, fn ($a, $b) => [$b['is_current'], $b['enough']] <=> [$a['is_current'], $a['enough']]);

        return $rows;
    }

    /** Before approval, send the request to a different location that has the stock. */
    public function changeSource(StockTransfer $transfer, string $newFromLocationId): StockTransfer
    {
        if (! $transfer->isRequested()) {
            throw ValidationException::withMessages(['from_location_id' => 'The sending location can only be changed before approval.']);
        }
        $from = $this->activeLocation($transfer->company_id, $newFromLocationId, 'from_location_id');
        $to = $this->activeLocation($transfer->company_id, $transfer->to_location_id, 'to_location_id');
        $this->assertTransferPair($from, $to, 'from_location_id');

        $transfer->update(['from_location_id' => $from->id]);

        return $transfer->refresh()->load(['items', 'fromLocation:id,name,type', 'toLocation:id,name,type']);
    }

    /** Qty promised on open (requested / approved) transfers, per product. */
    private function openTransferQty(array $productIds, ?string $exceptTransferId = null): array
    {
        if ($productIds === []) {
            return [];
        }

        return StockTransferItem::query()
            ->whereIn('product_id', $productIds)
            ->when($exceptTransferId, fn ($q) => $q->where('stock_transfer_id', '!=', $exceptTransferId))
            ->whereHas('stockTransfer', fn ($q) => $q->whereIn('status', ['draft', 'requested']))
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => round($rows->sum(fn ($row) => $row->dispatchQty()), 3))
            ->all();
    }

    private function createInterCompany(int|string $companyId, array $data, string $status): StockTransfer
    {
        $toCompanyId = filled($data['to_company_id'] ?? null) ? (int) $data['to_company_id'] : null;
        if ($toCompanyId !== null && (string) $toCompanyId === (string) $companyId) {
            throw ValidationException::withMessages(['to_company_id' => 'Destination company must differ from the source company.']);
        }

        if ($toCompanyId !== null) {
            abort_unless(Company::whereKey($toCompanyId)->where('is_active', true)->exists(), 422, 'Destination company not found.');
        }

        $names = Product::forCompany($companyId)
            ->whereIn('id', collect($data['items'])->pluck('product_id'))
            ->pluck('name', 'id');

        return DB::transaction(function () use ($companyId, $toCompanyId, $data, $names, $status) {
            $transfer = StockTransfer::create([
                'company_id'       => $companyId,
                'to_company_id'    => $toCompanyId,
                'transfer_type'    => 'inter_company',
                'from_location_id' => $data['from_location_id'] ?? null,
                'to_location_id'   => $data['to_location_id'] ?? null,
                'transfer_no'      => $this->nextTransferNo($companyId),
                'transfer_date'    => $data['transfer_date'],
                'status'           => $status,
                'notes'            => $data['notes'] ?? null,
            ]);

            $transfer->items()->createMany(collect($data['items'])->map(fn ($i) => [
                'product_id'         => $i['product_id'],
                'product_batch_id'   => $i['product_batch_id'] ?? null,
                'product_name'       => $names[$i['product_id']] ?? 'Item',
                'qty'                => $i['qty'],
                'received_qty'       => 0,
            ])->all());

            return $transfer->load(['items', 'fromCompany:id,name', 'toCompany:id,name', 'fromLocation:id,name', 'toLocation:id,name']);
        });
    }

    private function createIntraCompany(int|string $companyId, array $data, string $status): StockTransfer
    {
        $fromLocationId = $data['from_location_id'] ?? null;
        $toLocationId = $data['to_location_id'] ?? null;

        if (! $fromLocationId || ! $toLocationId) {
            throw ValidationException::withMessages(['from_location_id' => 'Select both source and destination locations.']);
        }

        if ((string) $fromLocationId === (string) $toLocationId) {
            throw ValidationException::withMessages(['to_location_id' => 'Destination location must differ from the source location.']);
        }

        $fromLocation = $this->activeLocation($companyId, $fromLocationId, 'from_location_id');
        $toLocation = $this->activeLocation($companyId, $toLocationId, 'to_location_id');
        $this->assertTransferPair($fromLocation, $toLocation);

        $names = Product::forCompany($companyId)
            ->whereIn('id', collect($data['items'])->pluck('product_id'))
            ->pluck('name', 'id');

        return DB::transaction(function () use ($companyId, $fromLocationId, $toLocationId, $data, $names, $status) {
            $transfer = StockTransfer::create([
                'company_id'       => $companyId,
                'to_company_id'    => null,
                'transfer_type'    => 'intra_company',
                'from_location_id' => $fromLocationId,
                'to_location_id'   => $toLocationId,
                'transfer_no'      => $this->nextTransferNo($companyId),
                'transfer_date'    => $data['transfer_date'],
                'status'           => $status,
                'notes'            => $data['notes'] ?? null,
            ]);

            $transfer->items()->createMany(collect($data['items'])->map(fn ($i) => [
                'product_id'       => $i['product_id'],
                'product_batch_id' => $i['product_batch_id'] ?? null,
                'product_name'     => $names[$i['product_id']] ?? 'Item',
                'qty'              => $i['qty'],
                'received_qty'     => 0,
            ])->all());

            return $transfer->load(['items', 'fromCompany:id,name', 'fromLocation:id,name', 'toLocation:id,name']);
        });
    }

    /** HO approves a requested transfer, optionally with per-line approved quantities. */
    public function approve(StockTransfer $transfer, array $approvals = [], ?int $userId = null, int|string|null $toCompanyId = null, int|string|null $fromCompanyId = null, ?string $fromLocationId = null): StockTransfer
    {
        if ($fromLocationId !== null && $transfer->isRequested() && $transfer->isIntraCompany() && (string) $fromLocationId !== (string) $transfer->from_location_id) {
            $transfer = $this->changeSource($transfer, $fromLocationId);
        }
        if ($transfer->isDraft()) {
            return $transfer->load(['items', 'fromCompany:id,name', 'toCompany:id,name', 'fromLocation:id,name', 'toLocation:id,name']);
        }
        if (! $transfer->isRequested()) {
            throw ValidationException::withMessages(['status' => 'Only requested transfers can be approved.']);
        }

        $transfer->loadMissing('items');
        $approvalsById = collect($approvals)->keyBy('id');

        foreach ($transfer->items as $item) {
            $row = $approvalsById->get($item->id);
            $approvedQty = $row ? (float) $row['approved_qty'] : (float) $item->qty;
            $approvedQty = max(0.0, min($approvedQty, (float) $item->qty));
            $item->update([
                'approved_qty'     => $approvedQty,
                'rejected_qty'     => round((float) $item->qty - $approvedQty, 3),
                'rejection_reason' => $row['rejection_reason'] ?? null,
            ]);
        }

        $transfer->refresh()->load('items');
        if ($transfer->items->every(fn ($i) => $i->dispatchQty() <= 0)) {
            throw ValidationException::withMessages(['approvals' => 'At least one line must have an approved quantity greater than zero.']);
        }

        if ($transfer->isIntraCompany()) {
            foreach ($transfer->items as $item) {
                $needed = $item->dispatchQty();
                if ($needed <= 0 || ! $item->product_id) {
                    continue;
                }
                $reserved = $this->openLocationQty($transfer->from_location_id, [$item->product_id], $transfer->id);
                $free = round(max(0, $this->locationStock->available($transfer->from_location_id, $item->product_id) - (float) ($reserved[$item->product_id] ?? 0)), 3);
                if ($needed > $free + 0.0001) {
                    throw ValidationException::withMessages([
                        'approvals' => "Not enough stock of {$item->product_name} at the sending location: {$free} free, {$needed} approved. Choose another location that has it.",
                    ]);
                }
            }

            $transfer->update(['status' => 'draft', 'approved_at' => now(), 'rejection_reason' => null]);
            $this->logTransfer($transfer->company_id, $userId, 'approve', $transfer->fresh(), 'Transfer approved');

            return $transfer->load(['items', 'fromLocation:id,name,type', 'toLocation:id,name,type']);
        }

        $destinationId = $transfer->to_company_id ?: $toCompanyId;
        if (! $transfer->isIntraCompany() && ! $destinationId) {
            throw ValidationException::withMessages(['to_company_id' => 'Choose the receiving company before approving.']);
        }
        if ($destinationId && (string) $destinationId === (string) $transfer->company_id && ! $transfer->isIntraCompany()) {
            throw ValidationException::withMessages(['to_company_id' => 'Destination company must differ from the sending company.']);
        }
        if ($destinationId && ! $transfer->isIntraCompany()) {
            abort_unless(Company::whereKey($destinationId)->where('is_active', true)->exists(), 422, 'Destination company not found.');
        }

        foreach ($transfer->items as $item) {
            $needed = $item->dispatchQty();
            if ($needed <= 0 || ! $item->product_id) {
                continue;
            }
            $product = Product::forCompany($transfer->company_id)->lockForUpdate()->find($item->product_id);
            if (! $product) {
                continue;
            }
            $reservedElsewhere = (float) StockTransferItem::query()
                ->where('product_id', $item->product_id)
                ->where('stock_transfer_id', '!=', $transfer->id)
                ->whereHas('stockTransfer', fn ($q) => $q->where('company_id', $transfer->company_id)->whereIn('status', ['draft', 'requested']))
                ->get()
                ->sum(fn ($row) => $row->dispatchQty());
            $available = max(0, round((float) $product->current_stock - $reservedElsewhere, 3));
            if ($needed > $available + 0.0001) {
                throw ValidationException::withMessages([
                    'approvals' => "Not enough stock of {$item->product_name} at the sending company: {$available} free, {$needed} approved. Choose another company that has it.",
                ]);
            }
        }

        $transfer->update([
            'status' => 'draft',
            'approved_at' => now(),
            'rejection_reason' => null,
            'to_company_id' => $transfer->isIntraCompany() ? $transfer->to_company_id : $destinationId,
        ]);

        $this->logTransfer($transfer->company_id, $userId, 'approve', $transfer->fresh(), 'Transfer approved');

        return $transfer->load(['items', 'fromCompany:id,name', 'toCompany:id,name', 'fromLocation:id,name', 'toLocation:id,name']);
    }

    /** HO rejects a requested transfer. */
    public function reject(StockTransfer $transfer, ?string $reason = null, ?int $userId = null): StockTransfer
    {
        if (! $transfer->isRequested()) {
            throw ValidationException::withMessages(['status' => 'Only requested transfers can be rejected.']);
        }

        $transfer->update(['status' => 'rejected', 'rejection_reason' => $reason]);

        $this->logTransfer($transfer->company_id, $userId, 'reject', $transfer->fresh(), 'Transfer rejected', ['reason' => $reason]);

        return $transfer->load(['items', 'fromCompany:id,name', 'toCompany:id,name', 'fromLocation:id,name', 'toLocation:id,name']);
    }

    /** HO redirects an in-transit transfer to a different destination shop. */
    public function redirect(StockTransfer $transfer, int $newToCompanyId, ?int $userId = null): StockTransfer
    {
        if ($transfer->isIntraCompany()) {
            throw ValidationException::withMessages(['status' => 'Location transfers cannot be redirected to another company.']);
        }

        if (! $transfer->isInTransit()) {
            throw ValidationException::withMessages(['status' => 'Only in-transit transfers can be redirected.']);
        }
        if ((string) $newToCompanyId === (string) $transfer->company_id) {
            throw ValidationException::withMessages(['to_company_id' => 'Destination must differ from the source company.']);
        }
        if ((string) $newToCompanyId === (string) $transfer->to_company_id) {
            throw ValidationException::withMessages(['to_company_id' => 'Choose a different destination shop.']);
        }
        abort_unless(Company::whereKey($newToCompanyId)->where('is_active', true)->exists(), 422, 'Destination company not found.');

        $previousName = $transfer->toCompany?->name ?? "#{$transfer->to_company_id}";
        $transfer->update([
            'redirected_from_company_id' => $transfer->to_company_id,
            'redirected_at'              => now(),
            'redirected_by'              => $userId,
            'to_company_id'              => $newToCompanyId,
            'notes'                      => trim(($transfer->notes ? $transfer->notes."\n" : '')."Redirected in transit from {$previousName}."),
        ]);

        $this->logTransfer($transfer->company_id, $userId, 'redirect', $transfer->fresh(), 'Transfer redirected in transit', [
            'to_company_id' => $newToCompanyId,
        ]);

        return $transfer->load(['items', 'fromCompany:id,name', 'toCompany:id,name', 'fromLocation:id,name', 'toLocation:id,name', 'redirectedFromCompany:id,name']);
    }

    /** Dispatch: deduct stock from the source company or location. */
    public function dispatch(StockTransfer $transfer, ?int $userId = null): StockTransfer
    {
        if (! $transfer->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only draft transfers can be dispatched.']);
        }

        return DB::transaction(function () use ($transfer, $userId) {
            $transfer->loadMissing('items');

            foreach ($transfer->items as $item) {
                $dispatchQty = $item->dispatchQty();
                if ($dispatchQty <= 0 || ! $item->product_id) {
                    continue;
                }

                if ($transfer->isIntraCompany()) {
                    $available = $this->locationStock->available($transfer->from_location_id, $item->product_id);
                    if ($available < $dispatchQty) {
                        throw ValidationException::withMessages([
                            'items' => "Not enough stock at source location for {$item->product_name}: {$available} available, {$dispatchQty} needed.",
                        ]);
                    }
                } else {
                    $product = Product::forCompany($transfer->company_id)->lockForUpdate()->find($item->product_id);
                    if (! $product) {
                        continue;
                    }
                    if ((float) $product->current_stock < $dispatchQty) {
                        throw ValidationException::withMessages([
                            'items' => "Not enough stock for {$item->product_name}: {$product->current_stock} available, {$dispatchQty} needed.",
                        ]);
                    }
                }
            }

            foreach ($transfer->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $dispatchQty = $item->dispatchQty();
                if ($dispatchQty <= 0) {
                    continue;
                }

                if ($transfer->isIntraCompany()) {
                    $this->locationStock->adjust(
                        $transfer->company_id,
                        $transfer->from_location_id,
                        $item->product_id,
                        'out',
                        $dispatchQty,
                    );
                    $this->supervisorCommission->accrue(
                        $transfer->company_id,
                        $item->product_id,
                        $dispatchQty,
                        'transfer',
                        'stock-transfer',
                        $transfer->id,
                    );
                } else {
                    $product = Product::forCompany($transfer->company_id)->lockForUpdate()->find($item->product_id);
                    if (! $product) {
                        continue;
                    }

                    $this->inventory->post(
                        product: $product,
                        direction: 'out',
                        qty: $dispatchQty,
                        unitCost: (float) $product->cost_price,
                        referenceType: 'transfer',
                        referenceId: $transfer->id,
                        note: "Transfer {$transfer->transfer_no} dispatched",
                        userId: $userId,
                        productBatchId: $item->product_batch_id,
                    );
                    $product->save();

                    if ($item->product_batch_id) {
                        $batch = ProductBatch::where('id', $item->product_batch_id)->lockForUpdate()->first();
                        if ($batch) {
                            $batch->decrement('remaining_qty', $dispatchQty);
                            if ((float) $batch->fresh()->remaining_qty <= 0) {
                                $batch->update(['status' => 'depleted']);
                            }
                        }
                    } else {
                        $this->inventory->consumeBatchesFifo($product->id, $dispatchQty);
                    }

                    $this->supervisorCommission->accrue(
                        $transfer->company_id,
                        $item->product_id,
                        $dispatchQty,
                        'transfer',
                        'stock-transfer',
                        $transfer->id,
                        (float) $product->cost_price,
                    );
                }
            }

            $transfer->update(['status' => 'in_transit', 'dispatched_at' => now()]);

            $this->logTransfer($transfer->company_id, $userId, 'dispatch', $transfer->fresh(), 'Transfer dispatched');

            return $transfer->refresh()->load(['items', 'fromCompany:id,name', 'toCompany:id,name', 'fromLocation:id,name', 'toLocation:id,name']);
        });
    }

    /**
     * Receive: accepted qty lands at the destination company/location;
     * any rejected remainder returns to the source.
     */
    public function receive(StockTransfer $transfer, array $receipts, ?int $userId = null): StockTransfer
    {
        if (! $transfer->isInTransit()) {
            throw ValidationException::withMessages(['status' => 'Only in-transit transfers can be received.']);
        }

        return DB::transaction(function () use ($transfer, $receipts, $userId) {
            $transfer->loadMissing('items');

            foreach ($transfer->items as $item) {
                if (! $item->product_id) {
                    continue;
                }

                $sentQty = $item->dispatchQty();
                if ($sentQty <= 0) {
                    $item->update(['received_qty' => 0]);

                    continue;
                }

                $requested = array_key_exists($item->id, $receipts) ? (float) $receipts[$item->id] : $sentQty;
                $received = max(0.0, min($requested, $sentQty));
                $rejected = $sentQty - $received;

                if ($transfer->isIntraCompany()) {
                    $this->receiveIntraLine($transfer, $item, $received, $rejected);
                } else {
                    $this->receiveInterLine($transfer, $item, $received, $rejected, $userId);
                }

                $item->update(['received_qty' => $received]);
            }

            $transfer->update(['status' => 'received', 'received_at' => now()]);

            $this->logTransfer($transfer->company_id, $userId, 'receive', $transfer->fresh(), 'Transfer received');

            return $transfer->refresh()->load(['items', 'fromCompany:id,name', 'toCompany:id,name', 'fromLocation:id,name', 'toLocation:id,name']);
        });
    }

    private function receiveIntraLine(StockTransfer $transfer, $item, float $received, float $rejected): void
    {
        if ($received > 0) {
            $this->locationStock->adjust(
                $transfer->company_id,
                $transfer->to_location_id,
                $item->product_id,
                'in',
                $received,
            );
        }

        if ($rejected > 0) {
            $this->locationStock->adjust(
                $transfer->company_id,
                $transfer->from_location_id,
                $item->product_id,
                'in',
                $rejected,
            );
        }
    }

    private function receiveInterLine(StockTransfer $transfer, $item, float $received, float $rejected, ?int $userId): void
    {
        $destCompanyId = $transfer->to_company_id;
        $sourceProduct = Product::query()->lockForUpdate()->find($item->product_id);
        $sourceBatch = $item->product_batch_id ? ProductBatch::find($item->product_batch_id) : null;

        if ($received > 0 && $destCompanyId) {
            $destProduct = $this->ensureDestinationProduct($destCompanyId, $sourceProduct, $item);

            $item->forceFill(['received_product_id' => $destProduct->id]);
            $destBatch = ProductBatch::create([
                'company_id'       => $destCompanyId,
                'product_id'       => $destProduct->id,
                'purchase_id'      => $sourceBatch?->purchase_id,
                'purchase_item_id' => $sourceBatch?->purchase_item_id,
                'supplier_id'      => $sourceBatch?->supplier_id,
                'batch_no'         => $sourceBatch?->batch_no ?? $transfer->transfer_no,
                'barcode'          => 'PLT'.$destCompanyId.'-'.strtoupper(substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 12)),
                'qty'              => $received,
                'remaining_qty'    => $received,
                'cost_price'       => (float) ($sourceProduct?->cost_price ?? $destProduct->cost_price),
                'status'           => 'active',
                'received_at'      => now(),
            ]);

            $this->inventory->post(
                product: $destProduct,
                direction: 'in',
                qty: $received,
                unitCost: (float) ($sourceProduct?->cost_price ?? $destProduct->cost_price),
                referenceType: 'transfer',
                referenceId: $transfer->id,
                note: "Transfer {$transfer->transfer_no} received",
                userId: $userId,
                productBatchId: $destBatch->id,
            );
            $destProduct->save();
            $item->forceFill(['received_batch_id' => $destBatch->id])->save();
        }

        if ($rejected > 0 && $sourceProduct) {
            if ($sourceBatch) {
                $sourceBatch->increment('remaining_qty', $rejected);
                if ($sourceBatch->status === 'depleted') {
                    $sourceBatch->update(['status' => 'active']);
                }
            }
            $this->inventory->post(
                product: $sourceProduct,
                direction: 'in',
                qty: $rejected,
                unitCost: (float) $sourceProduct->cost_price,
                referenceType: 'transfer',
                referenceId: $transfer->id,
                note: "Transfer {$transfer->transfer_no} rejected qty returned",
                userId: $userId,
            );
            $sourceProduct->save();
        }
    }

    /** Cancel: in-transit stock returns to source; drafts just close. */
    public function cancel(StockTransfer $transfer, ?int $userId = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId) {
            if ($transfer->isInTransit()) {
                $transfer->loadMissing('items');
                foreach ($transfer->items as $item) {
                    if (! $item->product_id) {
                        continue;
                    }
                    $dispatchQty = $item->dispatchQty();
                    if ($dispatchQty <= 0) {
                        continue;
                    }

                    if ($transfer->isIntraCompany()) {
                        $this->locationStock->adjust(
                            $transfer->company_id,
                            $transfer->from_location_id,
                            $item->product_id,
                            'in',
                            $dispatchQty,
                        );
                    } else {
                        $product = Product::forCompany($transfer->company_id)->lockForUpdate()->find($item->product_id);
                        if (! $product) {
                            continue;
                        }
                        $this->inventory->post(
                            product: $product,
                            direction: 'in',
                            qty: $dispatchQty,
                            unitCost: (float) $product->cost_price,
                            referenceType: 'transfer',
                            referenceId: $transfer->id,
                            note: "Transfer {$transfer->transfer_no} cancelled",
                            userId: $userId,
                        );
                        $product->save();
                        if ($item->product_batch_id) {
                            $batch = ProductBatch::where('id', $item->product_batch_id)->lockForUpdate()->first();
                            if ($batch) {
                                $batch->increment('remaining_qty', $dispatchQty);
                                if ($batch->status === 'depleted') {
                                    $batch->update(['status' => 'active']);
                                }
                            }
                        }
                    }
                }
            }
            $transfer->update(['status' => 'cancelled']);

            if ($transfer->isInTransit() && ! $transfer->isIntraCompany()) {
                $this->supervisorCommission->reverseForTransfer($transfer->fresh());
            }

            $this->logTransfer($transfer->company_id, $userId, 'cancel', $transfer->fresh(), 'Transfer cancelled');

            return $transfer->refresh();
        });
    }

    /**
     * Match the destination SKU if it already exists; otherwise copy the source
     * product into the destination company so receive is never blocked.
     */
    private function ensureDestinationProduct(int|string $destCompanyId, ?Product $sourceProduct, $item): Product
    {
        $sourceProduct?->loadMissing(['category', 'brand', 'unit']);
        $sku = $sourceProduct?->sku;
        $name = $sourceProduct?->name ?: ($item->product_name ?? 'Transferred product');

        $destProduct = $this->findDestinationProduct($destCompanyId, $sku, $name, $sourceProduct);
        if ($destProduct) {
            return $destProduct;
        }

        try {
            return $this->copyProductToCompany($destCompanyId, $sourceProduct, $sku, $name);
        } catch (\Illuminate\Database\QueryException $e) {
            $existing = $this->findDestinationProduct($destCompanyId, $sku, $name, $sourceProduct);
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * The same product at the receiving company is found by NAME. SKUs are
     * numbered per company (every company's first product is SKU-00001), so a
     * SKU or barcode alone could point at a completely different product; they
     * are only used to pick between products that share the name.
     */
    private function findDestinationProduct(int|string $destCompanyId, ?string $sku, string $name, ?Product $sourceProduct = null): ?Product
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return null;
        }
        $sameName = Product::forCompany($destCompanyId)->lockForUpdate()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($trimmed)])->get();
        if ($sameName->isEmpty()) {
            return null;
        }
        if (filled($sku)) {
            $match = $sameName->first(fn ($p) => mb_strtolower(trim((string) $p->sku)) === mb_strtolower(trim($sku)));
            if ($match) {
                return $match;
            }
        }

        return $sameName->first();
    }

    private function copyProductToCompany(int|string $destCompanyId, ?Product $sourceProduct, ?string $sku, string $name): Product
    {
        $skuTaken = filled($sku) && Product::forCompany($destCompanyId)->whereRaw('LOWER(TRIM(sku)) = ?', [mb_strtolower(trim($sku))])->exists();
        $destSku = filled($sku) && ! $skuTaken ? $sku : $this->nextDestinationSku($destCompanyId);

        if (! $sourceProduct) {
            return Product::create([
                'company_id'      => $destCompanyId,
                'sku'             => $destSku,
                'name'            => $name ?: 'Transferred product',
                'gst_rate'        => 0,
                'mrp'             => 0,
                'cost_price'      => 0,
                'dealer_price'    => 0,
                'wholesale_price' => 0,
                'retail_price'    => 0,
                'opening_stock'   => 0,
                'current_stock'   => 0,
                'status'          => 'active',
            ]);
        }

        $payload = [
            'company_id'        => $destCompanyId,
            'sku'               => $destSku,
            'name'              => $sourceProduct->name,
            // Keep the maker's barcode unless another product here already uses it.
            'barcode'           => filled($sourceProduct->barcode) && ! Product::forCompany($destCompanyId)->where('barcode', $sourceProduct->barcode)->exists()
                ? $sourceProduct->barcode : null,
            'hsn_code'          => $sourceProduct->hsn_code,
            'description'       => $sourceProduct->description,
            'gst_rate'          => $sourceProduct->gst_rate ?? 0,
            'mrp'               => $sourceProduct->mrp ?? 0,
            'cost_price'        => $sourceProduct->cost_price ?? 0,
            'dealer_price'      => $sourceProduct->dealer_price ?? 0,
            'wholesale_price'   => $sourceProduct->wholesale_price ?? 0,
            'retail_price'      => $sourceProduct->retail_price ?? 0,
            'reorder_level'     => $sourceProduct->reorder_level ?? 0,
            'opening_stock'     => 0,
            'current_stock'     => 0,
            'length_cm'         => $sourceProduct->length_cm,
            'width_cm'          => $sourceProduct->width_cm,
            'height_cm'         => $sourceProduct->height_cm,
            'images'            => $sourceProduct->images,
            'status'            => $sourceProduct->status ?: 'active',
            'is_rental'         => (bool) $sourceProduct->is_rental,
            'rental_daily_rate' => $sourceProduct->rental_daily_rate,
        ];

        try {
            $payload['category_id'] = $this->replicateMaster(ProductCategory::class, $destCompanyId, $sourceProduct->category);
            $payload['brand_id'] = $this->replicateMaster(ProductBrand::class, $destCompanyId, $sourceProduct->brand);
            $payload['unit_id'] = $this->replicateMaster(ProductUnit::class, $destCompanyId, $sourceProduct->unit, ['short_name']);
        } catch (\Throwable) {
            $payload['category_id'] = $payload['category_id'] ?? null;
            $payload['brand_id'] = $payload['brand_id'] ?? null;
            $payload['unit_id'] = $payload['unit_id'] ?? null;
        }

        return Product::create($payload);
    }

    private function replicateMaster(string $model, int|string $companyId, ?object $source, array $extra = []): ?string
    {
        if (! $source) {
            return null;
        }

        $existing = $model::query()
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('code', $source->code)->orWhere('name', $source->name))
            ->first();
        if ($existing) {
            return $existing->id;
        }

        $payload = [
            'company_id'  => $companyId,
            'code'        => $this->uniqueMasterCode($model, $companyId, (string) $source->code),
            'name'        => $source->name,
            'description' => $source->description,
            'status'      => $source->status ?: 'active',
        ];
        foreach ($extra as $field) {
            $payload[$field] = $source->{$field} ?? null;
        }

        return $model::create($payload)->id;
    }

    private function uniqueMasterCode(string $model, int|string $companyId, string $code): string
    {
        $base = $code !== '' ? $code : 'X';
        $candidate = $base;
        $n = 1;
        while ($model::query()->where('company_id', $companyId)->where('code', $candidate)->exists()) {
            $candidate = $base.'-'.$n++;
        }

        return $candidate;
    }

    private function nextDestinationSku(int|string $companyId): string
    {
        return \App\Support\Numbering\DocumentNumber::next(Product::query()->where('company_id', $companyId), 'sku', 'SKU-', 5);
    }

    private function logTransfer(int|string $companyId, ?int $userId, string $action, StockTransfer $transfer, string $description, ?array $meta = null): void
    {
        $this->activity->log(
            $companyId,
            $userId,
            $action,
            'transfer',
            'stock_transfer',
            $transfer->id,
            $description,
            array_merge(['transfer_no' => $transfer->transfer_no, 'status' => $transfer->status], $meta ?? []),
        );
    }

    private function assertLocationBelongsToCompany(int|string $companyId, string $locationId, string $field): void
    {
        $ok = Location::forCompany($companyId)->whereKey($locationId)->where('is_active', true)->exists();
        if (! $ok) {
            throw ValidationException::withMessages([$field => 'Select a valid active location for this company.']);
        }
    }

    private function nextTransferNo(int|string $companyId): string
    {
        return \App\Support\Numbering\DocumentNumber::next(StockTransfer::query()->where('company_id', $companyId), 'transfer_no', 'TRF-', 6);
    }
}
