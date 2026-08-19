<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockTransfer;
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
            ->with(['fromCompany:id,name', 'toCompany:id,name', 'fromLocation:id,name', 'toLocation:id,name'])
            ->withCount('items')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('transfer_no', 'like', "%{$filters['search']}%"))
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

    public function create(int|string $companyId, array $data, ?int $userId = null, bool $autoApprove = true): StockTransfer
    {
        $transferType = ($data['transfer_type'] ?? 'inter_company') === 'intra_company' ? 'intra_company' : 'inter_company';
        $status = $autoApprove ? 'draft' : 'requested';

        if ($transferType === 'intra_company') {
            $transfer = $this->createIntraCompany($companyId, $data, $status);
        } else {
            $transfer = $this->createInterCompany($companyId, $data, $status);
        }

        $this->logTransfer($companyId, $userId, 'create', $transfer, 'Transfer created');

        return $transfer;
    }

    private function createInterCompany(int|string $companyId, array $data, string $status): StockTransfer
    {
        $toCompanyId = (int) $data['to_company_id'];
        if ((string) $toCompanyId === (string) $companyId) {
            throw ValidationException::withMessages(['to_company_id' => 'Destination company must differ from the source company.']);
        }

        abort_unless(Company::whereKey($toCompanyId)->where('is_active', true)->exists(), 422, 'Destination company not found.');

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

        $this->assertLocationBelongsToCompany($companyId, $fromLocationId, 'from_location_id');
        $this->assertLocationBelongsToCompany($companyId, $toLocationId, 'to_location_id');

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
    public function approve(StockTransfer $transfer, array $approvals = [], ?int $userId = null): StockTransfer
    {
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

        $transfer->update(['status' => 'draft', 'approved_at' => now(), 'rejection_reason' => null]);

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

        return $transfer->load(['items', 'fromCompany:id,name', 'toCompany:id,name', 'fromLocation:id,name', 'toLocation:id,name']);
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
        $sourceProduct = Product::forCompany($transfer->company_id)->lockForUpdate()->find($item->product_id);
        $sourceBatch = $item->product_batch_id ? ProductBatch::find($item->product_batch_id) : null;

        if ($received > 0 && $destCompanyId) {
            $sku = $sourceProduct?->sku;
            $destProduct = Product::forCompany($destCompanyId)
                ->when(filled($sku), fn ($q) => $q->where('sku', $sku))
                ->when(blank($sku), fn ($q) => $q->where('name', $item->product_name))
                ->lockForUpdate()
                ->first();

            if (! $destProduct) {
                throw ValidationException::withMessages([
                    'items' => "No matching product at destination for {$item->product_name}".($sku ? " (SKU {$sku})" : '').'.',
                ]);
            }

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

            $this->logTransfer($transfer->company_id, $userId, 'cancel', $transfer->fresh(), 'Transfer cancelled');

            return $transfer->refresh();
        });
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
        $count = StockTransfer::withTrashed()->where('company_id', $companyId)->count();

        return 'TRF-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
