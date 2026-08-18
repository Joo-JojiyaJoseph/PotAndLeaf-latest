<?php

namespace App\Services;

use App\Models\Company;
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
        private readonly SupervisorCommissionService $supervisorCommission,
    ) {}

    public function list(int|string $companyId, array $filters): LengthAwarePaginator
    {
        return StockTransfer::query()
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhere('to_company_id', $companyId))
            ->with(['fromCompany:id,name', 'toCompany:id,name'])
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
            ->with(['items', 'fromCompany:id,name', 'toCompany:id,name'])
            ->whereKey($id)->first();
    }

    public function create(int|string $companyId, array $data, ?int $userId = null, bool $autoApprove = true): StockTransfer
    {
        $toCompanyId = (int) $data['to_company_id'];
        if ((string) $toCompanyId === (string) $companyId) {
            throw ValidationException::withMessages(['to_company_id' => 'Destination company must differ from the source company.']);
        }

        abort_unless(Company::whereKey($toCompanyId)->where('is_active', true)->exists(), 422, 'Destination company not found.');

        $names = Product::forCompany($companyId)
            ->whereIn('id', collect($data['items'])->pluck('product_id'))
            ->pluck('name', 'id');

        // Branch-initiated transfers need HO approval before stock can move.
        $status = $autoApprove ? 'draft' : 'requested';

        return DB::transaction(function () use ($companyId, $toCompanyId, $data, $names, $status) {
            $transfer = StockTransfer::create([
                'company_id'       => $companyId,
                'to_company_id'    => $toCompanyId,
                'from_location_id' => null,
                'to_location_id'   => null,
                'transfer_no'      => $this->nextTransferNo($companyId),
                'transfer_date'    => $data['transfer_date'],
                'status'           => $status,
                'notes'            => $data['notes'] ?? null,
            ]);

            $transfer->items()->createMany(collect($data['items'])->map(fn ($i) => [
                'product_id'   => $i['product_id'],
                'product_batch_id' => $i['product_batch_id'] ?? null,
                'product_name' => $names[$i['product_id']] ?? 'Item',
                'qty'          => $i['qty'],
                'received_qty' => 0,
            ])->all());

            return $transfer->load(['items', 'fromCompany:id,name', 'toCompany:id,name']);
        });
    }

    /** HO approves a requested transfer, making it dispatchable. */
    public function approve(StockTransfer $transfer, ?int $userId = null): StockTransfer
    {
        if ($transfer->isDraft()) {
            return $transfer->load(['items', 'fromCompany:id,name', 'toCompany:id,name']); // already approved
        }
        if (! $transfer->isRequested()) {
            throw ValidationException::withMessages(['status' => 'Only requested transfers can be approved.']);
        }

        $transfer->update(['status' => 'draft', 'approved_at' => now(), 'rejection_reason' => null]);

        return $transfer->load(['items', 'fromCompany:id,name', 'toCompany:id,name']);
    }

    /** HO rejects a requested transfer. */
    public function reject(StockTransfer $transfer, ?string $reason = null, ?int $userId = null): StockTransfer
    {
        if (! $transfer->isRequested()) {
            throw ValidationException::withMessages(['status' => 'Only requested transfers can be rejected.']);
        }

        $transfer->update(['status' => 'rejected', 'rejection_reason' => $reason]);

        return $transfer->load(['items', 'fromCompany:id,name', 'toCompany:id,name']);
    }

    /** HO redirects an in-transit transfer to a different destination shop. */
    public function redirect(StockTransfer $transfer, int $newToCompanyId, ?int $userId = null): StockTransfer
    {
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
            'to_company_id' => $newToCompanyId,
            'notes'         => trim(($transfer->notes ? $transfer->notes."\n" : '')."Redirected in transit from {$previousName}."),
        ]);

        return $transfer->load(['items', 'fromCompany:id,name', 'toCompany:id,name']);
    }

    /** Dispatch: deduct stock from the source company. */
    public function dispatch(StockTransfer $transfer, ?int $userId = null): StockTransfer
    {
        if (! $transfer->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only draft transfers can be dispatched.']);
        }

        return DB::transaction(function () use ($transfer, $userId) {
            $transfer->loadMissing('items');

            foreach ($transfer->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $product = Product::forCompany($transfer->company_id)->lockForUpdate()->find($item->product_id);
                if (! $product) {
                    continue;
                }
                if ((float) $product->current_stock < (float) $item->qty) {
                    throw ValidationException::withMessages([
                        'items' => "Not enough stock for {$item->product_name}: {$product->current_stock} available, {$item->qty} needed.",
                    ]);
                }
            }

            foreach ($transfer->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $product = Product::forCompany($transfer->company_id)->lockForUpdate()->find($item->product_id);
                if (! $product) {
                    continue;
                }

                $this->inventory->post(
                    product: $product,
                    direction: 'out',
                    qty: (float) $item->qty,
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
                        $batch->decrement('remaining_qty', (float) $item->qty);
                        if ((float) $batch->fresh()->remaining_qty <= 0) $batch->update(['status' => 'depleted']);
                    }
                }

                $this->supervisorCommission->accrue(
                    $transfer->company_id,
                    $item->product_id,
                    (float) $item->qty,
                    'transfer',
                    'stock-transfer',
                    $transfer->id,
                    (float) $product->cost_price,
                );
            }

            $transfer->update(['status' => 'in_transit', 'dispatched_at' => now()]);

            return $transfer->refresh()->load(['items', 'fromCompany:id,name', 'toCompany:id,name']);
        });
    }

    /**
     * Receive: accepted qty lands at the destination company (matched by SKU),
     * any rejected remainder returns to the source company.
     */
    public function receive(StockTransfer $transfer, array $receipts, ?int $userId = null): StockTransfer
    {
        if (! $transfer->isInTransit()) {
            throw ValidationException::withMessages(['status' => 'Only in-transit transfers can be received.']);
        }

        return DB::transaction(function () use ($transfer, $receipts, $userId) {
            $transfer->loadMissing('items');
            $destCompanyId = $transfer->to_company_id;

            foreach ($transfer->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $requested = array_key_exists($item->id, $receipts) ? (float) $receipts[$item->id] : (float) $item->qty;
                $received = max(0.0, min($requested, (float) $item->qty));
                $rejected = (float) $item->qty - $received;

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

                    // Mint a batch at the receiving shop that carries the source
                    // purchase/product so it stays traceable and scannable.
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
                    // Rejected qty returns to source stock and its batch.
                    if ($sourceBatch) {
                        $sourceBatch->increment('remaining_qty', $rejected);
                        if ($sourceBatch->status === 'depleted') $sourceBatch->update(['status' => 'active']);
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

                $item->update(['received_qty' => $received]);
            }

            $transfer->update(['status' => 'received', 'received_at' => now()]);

            return $transfer->refresh()->load(['items', 'fromCompany:id,name', 'toCompany:id,name']);
        });
    }

    /** Cancel: in-transit stock returns to source company; drafts just close. */
    public function cancel(StockTransfer $transfer, ?int $userId = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId) {
            if ($transfer->isInTransit()) {
                $transfer->loadMissing('items');
                foreach ($transfer->items as $item) {
                    if (! $item->product_id) {
                        continue;
                    }
                    $product = Product::forCompany($transfer->company_id)->lockForUpdate()->find($item->product_id);
                    if (! $product) {
                        continue;
                    }
                    $this->inventory->post(
                        product: $product,
                        direction: 'in',
                        qty: (float) $item->qty,
                        unitCost: (float) $product->cost_price,
                        referenceType: 'transfer',
                        referenceId: $transfer->id,
                        note: "Transfer {$transfer->transfer_no} cancelled",
                        userId: $userId,
                    );
                    $product->save();
                }
            }
            $transfer->update(['status' => 'cancelled']);

            return $transfer->refresh();
        });
    }

    private function nextTransferNo(int|string $companyId): string
    {
        $count = StockTransfer::withTrashed()->where('company_id', $companyId)->count();

        return 'TRF-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
