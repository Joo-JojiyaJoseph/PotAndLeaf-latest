<?php

namespace App\Services;

use App\Actions\Products\CreateProduct;
use App\Models\Bom;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductionOrder;
use App\Support\Barcode\BarcodeGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly BarcodeGenerator $barcodes,
        private readonly CreateProduct $createProduct,
    ) {}

    // ---- Bill of Materials ----

    public function boms(int|string $companyId)
    {
        return Bom::forCompany($companyId)
            ->with(['product:id,sku,name', 'items.component:id,sku,name'])
            ->orderBy('name')->get();
    }

    public function upsertBom(int|string $companyId, array $data): Bom
    {
        return DB::transaction(function () use ($companyId, $data) {
            $productId = $data['product_id'] ?? null;
            if (! empty($data['new_product'])) {
                $created = $this->createProduct->handle($companyId, [
                    'sku'           => $data['new_product']['sku'],
                    'name'          => $data['new_product']['name'],
                    'unit_id'       => $data['new_product']['unit_id'] ?? null,
                    'status'        => 'active',
                    'opening_stock' => 0,
                ]);
                $productId = $created->id;
            }

            if (! $productId) {
                throw ValidationException::withMessages([
                    'product_id' => 'Select an output product or create a new one.',
                ]);
            }

            $bom = Bom::updateOrCreate(
                ['id' => $data['id'] ?? null, 'company_id' => $companyId],
                [
                    'product_id' => $productId,
                    'name'       => $data['name'],
                    'output_qty' => $data['output_qty'] ?? 1,
                    'is_active'  => $data['is_active'] ?? true,
                    'notes'      => $data['notes'] ?? null,
                ],
            );

            $bom->items()->delete();
            $bom->items()->createMany(collect($data['items'])->map(fn ($i) => [
                'component_product_id' => $i['component_product_id'],
                'qty'                  => $i['qty'],
            ])->all());

            return $bom->load(['product:id,sku,name', 'items.component:id,sku,name']);
        });
    }

    public function deleteBom(Bom $bom): void
    {
        $bom->delete();
    }

    // ---- Production orders ----

    public function orders(int|string $companyId, array $filters): LengthAwarePaginator
    {
        return ProductionOrder::forCompany($companyId)
            ->with('outputProduct:id,sku,name')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('order_date')->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 15), 100))
            ->withQueryString();
    }

    public function findOrder(int|string $companyId, string $id): ?ProductionOrder
    {
        return ProductionOrder::forCompany($companyId)
            ->with(['items', 'outputProduct:id,sku,name', 'bom:id,name', 'batches'])
            ->whereKey($id)->first();
    }

    public function createOrder(int|string $companyId, array $data, ?int $userId = null): ProductionOrder
    {
        $bom = Bom::forCompany($companyId)->with('items')->findOrFail($data['bom_id']);

        $order = ProductionOrder::create([
            'company_id'        => $companyId,
            'bom_id'            => $bom->id,
            'output_product_id' => $bom->product_id,
            'location_id'       => $data['location_id'] ?? null,
            'supervisor_id'     => $data['supervisor_id'] ?? null,
            'order_no'          => $this->nextOrderNo($companyId),
            'order_date'        => $data['order_date'],
            'output_quantity'   => $data['output_quantity'],
            'status'            => 'draft',
            'notes'             => $data['notes'] ?? null,
        ]);

        return $order->load(['outputProduct:id,sku,name', 'bom:id,name']);
    }

    /** Edit a draft order (recipe, quantity, date, supervisor, notes). */
    public function updateOrder(ProductionOrder $order, array $data): ProductionOrder
    {
        if (! $order->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only draft orders can be edited.']);
        }

        $bom = Bom::forCompany($order->company_id)->findOrFail($data['bom_id']);

        $order->update([
            'bom_id'            => $bom->id,
            'output_product_id' => $bom->product_id,
            'location_id'       => $data['location_id'] ?? null,
            'supervisor_id'     => $data['supervisor_id'] ?? null,
            'order_date'        => $data['order_date'],
            'output_quantity'   => $data['output_quantity'],
            'notes'             => $data['notes'] ?? null,
        ]);

        return $order->load(['outputProduct:id,sku,name', 'bom:id,name']);
    }
    public function complete(ProductionOrder $order, ?int $userId = null): ProductionOrder
    {
        // Idempotent: if it's already completed, a repeat call (double-click or a
        // retry after the first request already committed) returns the same order
        // as success instead of erroring.
        if ($order->isCompleted()) {
            return $order->load(['items', 'outputProduct:id,sku,name', 'bom:id,name', 'batches']);
        }

        if (! $order->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only draft orders can be completed.']);
        }

        $bom = Bom::forCompany($order->company_id)->with('items')->find($order->bom_id);
        if (! $bom || $bom->items->isEmpty()) {
            throw ValidationException::withMessages(['bom' => 'This order has no bill of materials to consume.']);
        }

        return DB::transaction(function () use ($order, $bom, $userId) {
            $factor = (float) $bom->output_qty > 0 ? (float) $order->output_quantity / (float) $bom->output_qty : 0.0;

            // Pre-check stock for all components.
            $plan = [];
            foreach ($bom->items as $bomItem) {
                $needed = round((float) $bomItem->qty * $factor, 3);
                $product = Product::forCompany($order->company_id)->lockForUpdate()->find($bomItem->component_product_id);
                if (! $product) {
                    continue;
                }
                if ((float) $product->current_stock < $needed) {
                    throw ValidationException::withMessages([
                        'items' => "Not enough {$product->name}: {$product->current_stock} available, {$needed} required.",
                    ]);
                }
                $plan[] = [$product, $needed];
            }

            $inputCost = 0.0;
            foreach ($plan as [$product, $needed]) {
                $unitCost = (float) $product->cost_price;
                $this->inventory->post(
                    product: $product, direction: 'out', qty: $needed, unitCost: $unitCost,
                    referenceType: 'production', referenceId: $order->id,
                    note: "Production {$order->order_no}", userId: $userId,
                );
                $product->save();
                $lineCost = round($needed * $unitCost, 2);
                $inputCost += $lineCost;
                $order->items()->create([
                    'component_product_id' => $product->id,
                    'product_name'         => $product->name,
                    'qty'                  => $needed,
                    'unit_cost'            => $unitCost,
                    'line_cost'            => $lineCost,
                ]);
            }

            $outQty = (float) $order->output_quantity;
            $unitCost = $outQty > 0 ? round($inputCost / $outQty, 4) : 0.0;
            $output = Product::forCompany($order->company_id)->lockForUpdate()->find($order->output_product_id);
            if ($output) {
                // Barcode the finished goods: one batch per production run.
                $batch = $this->makeProductionBatch($order, $output, $outQty, $unitCost);

                $this->inventory->post(
                    product: $output, direction: 'in', qty: $outQty, unitCost: $unitCost,
                    referenceType: 'production', referenceId: $order->id,
                    note: "Production {$order->order_no}", userId: $userId,
                    productBatchId: $batch?->id,
                );
                $output->cost_price = $unitCost;
                $output->save();
            }

            $order->update([
                'total_input_cost'       => round($inputCost, 2),
                'output_unit_cost'       => $unitCost,
                'commission_pending_qty' => $outQty,
                'status'                 => 'completed',
                'completed_at'           => now(),
            ]);

            return $order->refresh()->load(['items', 'outputProduct:id,sku,name', 'bom:id,name', 'batches']);
        });
    }

    /** Mint a barcoded finished-goods batch for a completed production run. */
    private function makeProductionBatch(ProductionOrder $order, Product $output, float $qty, float $unitCost): ?ProductBatch
    {
        $existing = ProductBatch::where('production_order_id', $order->id)->first();
        if ($existing) {
            return $existing;
        }

        return ProductBatch::create([
            'company_id'          => $order->company_id,
            'product_id'          => $output->id,
            'production_order_id' => $order->id,
            'location_id'         => $order->location_id,
            'batch_no'            => $order->order_no,
            'barcode'             => $this->barcodes->forProduction($order->company_id, $order->order_no),
            'qty'                 => $qty,
            'remaining_qty'       => $qty,
            'cost_price'          => $unitCost,
            'status'              => 'active',
            'received_at'         => now(),
        ]);
    }

    public function cancel(ProductionOrder $order, ?int $userId = null): ProductionOrder
    {
        return DB::transaction(function () use ($order, $userId) {
            if ($order->isCompleted()) {
                $order->loadMissing('items');

                // Un-produce the output.
                $output = Product::forCompany($order->company_id)->lockForUpdate()->find($order->output_product_id);
                if ($output) {
                    $this->inventory->post(
                        product: $output, direction: 'out', qty: (float) $order->output_quantity,
                        unitCost: (float) $order->output_unit_cost, referenceType: 'production-cancel',
                        referenceId: $order->id, note: "Reversal of {$order->order_no}", userId: $userId,
                    );
                    $output->save();
                }
                // Return the inputs.
                foreach ($order->items as $item) {
                    if (! $item->component_product_id) {
                        continue;
                    }
                    $product = Product::forCompany($order->company_id)->lockForUpdate()->find($item->component_product_id);
                    if (! $product) {
                        continue;
                    }
                    $this->inventory->post(
                        product: $product, direction: 'in', qty: (float) $item->qty,
                        unitCost: (float) $item->unit_cost, referenceType: 'production-cancel',
                        referenceId: $order->id, note: "Reversal of {$order->order_no}", userId: $userId,
                    );
                    $product->save();
                }
            }
            $order->update(['status' => 'cancelled']);

            return $order->refresh();
        });
    }

    private function nextOrderNo(int|string $companyId): string
    {
        $count = ProductionOrder::withTrashed()->forCompany($companyId)->count();

        return 'PRD-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
