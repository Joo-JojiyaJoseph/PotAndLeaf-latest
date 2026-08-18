<?php

namespace App\Services;

use App\Models\DamageEntry;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DamageEntryService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function list(int|string $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return DamageEntry::query()
            ->forCompany($companyId)
            ->with(['product:id,sku,name'])
            ->when(filled($filters['product_id'] ?? null), fn ($q) => $q->where('product_id', $filters['product_id']))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->whereDate('entry_date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->whereDate('entry_date', '<=', $filters['to']))
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @param array<string,mixed> $data */
    public function create(int|string $companyId, array $data, ?int $userId = null): DamageEntry
    {
        $qty = (float) ($data['qty'] ?? 0);
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => 'Quantity must be greater than zero.']);
        }

        return DB::transaction(function () use ($companyId, $data, $qty, $userId) {
            $product = Product::forCompany($companyId)->lockForUpdate()->find($data['product_id'] ?? null);
            if (! $product) {
                throw ValidationException::withMessages(['product_id' => 'Product not found.']);
            }

            // If a batch barcode was scanned, damage draws from that exact batch.
            $batch = null;
            if (! empty($data['product_batch_id'])) {
                $batch = \App\Models\ProductBatch::forCompany($companyId)->lockForUpdate()->find($data['product_batch_id']);
                if ($batch && (float) $batch->remaining_qty < $qty) {
                    throw ValidationException::withMessages(['qty' => "Batch {$batch->batch_no} has only {$batch->remaining_qty} left."]);
                }
            }

            if ((float) $product->current_stock < $qty) {
                throw ValidationException::withMessages([
                    'qty' => "Only {$product->current_stock} units available in stock.",
                ]);
            }

            $entry = DamageEntry::create([
                'company_id'  => $companyId,
                'product_id'  => $product->id,
                'product_batch_id' => $batch?->id,
                'location_id' => null,
                'entry_no'    => $this->nextEntryNo($companyId),
                'entry_date'  => $data['entry_date'] ?? now()->toDateString(),
                'qty'         => $qty,
                'reason'      => $data['reason'],
                'notes'       => $data['notes'] ?? null,
                'photo'       => $data['photo'] ?? null,
            ]);

            $this->inventory->post(
                $product,
                'out',
                $qty,
                (float) $product->cost_price,
                'damage',
                $entry->id,
                'Damage: '.$entry->reason.($entry->notes ? ' — '.$entry->notes : ''),
                $userId,
                $batch?->id,
            );
            $product->save();
            if ($batch) {
                $batch->decrement('remaining_qty', $qty);
                if ((float) $batch->fresh()->remaining_qty <= 0) $batch->update(['status' => 'depleted']);
            }

            return $entry->load(['product:id,sku,name']);
        });
    }

    private function nextEntryNo(int|string $companyId): string
    {
        $count = DamageEntry::withTrashed()->forCompany($companyId)->count();

        return 'DMG-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
