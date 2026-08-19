<?php

namespace App\Services;

use App\Actions\Products\CreateProduct;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderStage;
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
        private readonly ActivityLogService $activity,
    ) {}

    // ---- Bill of Materials ----

    public function boms(int|string|null $companyId, bool $activeOnly = false)
    {
        return Bom::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->with(['product:id,sku,name', 'items.component:id,sku,name', 'stages.items.component:id,sku,name'])
            ->orderBy('name')->get();
    }

    public function upsertBom(int|string $companyId, array $data, ?int $userId = null): Bom
    {
        return DB::transaction(function () use ($companyId, $data, $userId) {
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

            $isUpdate = filled($data['id'] ?? null);

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
            $bom->stages()->delete();

            $stages = $data['stages'] ?? [];
            if (! empty($stages)) {
                foreach ($stages as $index => $stageData) {
                    $stage = $bom->stages()->create([
                        'sort_order' => $index + 1,
                        'name'       => $stageData['name'],
                        'notes'      => $stageData['notes'] ?? null,
                    ]);
                    foreach ($stageData['items'] ?? [] as $item) {
                        $bom->items()->create([
                            'bom_stage_id'         => $stage->id,
                            'component_product_id' => $item['component_product_id'],
                            'qty'                  => $item['qty'],
                            'wastage_pct'          => $item['wastage_pct'] ?? 0,
                        ]);
                    }
                }
            } else {
                $bom->items()->createMany(collect($data['items'])->map(fn ($i) => [
                    'component_product_id' => $i['component_product_id'],
                    'qty'                  => $i['qty'],
                    'wastage_pct'          => $i['wastage_pct'] ?? 0,
                ])->all());
            }

            $bom = $bom->load(['product:id,sku,name', 'items.component:id,sku,name', 'stages.items.component:id,sku,name']);

            $this->activity->log(
                $companyId,
                $userId,
                $isUpdate ? 'update' : 'create',
                'production',
                'bom',
                $bom->id,
                "BOM {$bom->name} ".($isUpdate ? 'updated' : 'created'),
                ['product_id' => $bom->product_id, 'is_active' => (bool) $bom->is_active],
            );

            return $bom;
        });
    }

    public function deleteBom(Bom $bom, ?int $userId = null): void
    {
        $companyId = $bom->company_id;
        $name = $bom->name;
        $id = $bom->id;
        $bom->delete();

        $this->activity->log(
            $companyId,
            $userId,
            'delete',
            'production',
            'bom',
            $id,
            "BOM {$name} deleted",
        );
    }

    /** Preview material requirements and cost before completing production. */
    public function estimate(int|string $companyId, string $bomId, float $outputQuantity): array
    {
        $bom = Bom::forCompany($companyId)->where('is_active', true)->with('items.component:id,sku,name,cost_price,current_stock')->find($bomId);
        if (! $bom || $bom->items->isEmpty()) {
            throw ValidationException::withMessages(['bom_id' => 'Bill of materials not found or has no components.']);
        }

        $plan = $this->buildConsumptionPlan($bom, $outputQuantity, $companyId, lock: false);
        $totalCost = round(collect($plan)->sum('line_cost'), 2);
        $unitCost = $outputQuantity > 0 ? round($totalCost / $outputQuantity, 4) : 0.0;

        return [
            'output_quantity'     => round($outputQuantity, 3),
            'total_material_cost' => $totalCost,
            'unit_cost'           => $unitCost,
            'can_complete'        => collect($plan)->every(fn ($row) => $row['sufficient']),
            'items'               => $plan,
        ];
    }

    // ---- Production orders ----

    public function orders(int|string|null $companyId, array $filters): LengthAwarePaginator
    {
        return ProductionOrder::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->with(['outputProduct:id,sku,name', 'supervisor:id,name'])
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('order_date')->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 15), 100))
            ->withQueryString();
    }

    public function findOrder(int|string $companyId, string $id): ?ProductionOrder
    {
        return ProductionOrder::forCompany($companyId)
            ->with([
                'items', 'stages.supervisor:id,name', 'outputProduct:id,sku,name',
                'bom:id,name', 'batches', 'location:id,name', 'supervisor:id,name',
            ])
            ->whereKey($id)->first();
    }

    public function createOrder(int|string $companyId, array $data, ?int $userId = null): ProductionOrder
    {
        $bom = Bom::forCompany($companyId)->where('is_active', true)->with(['items', 'stages'])->find($data['bom_id']);
        if (! $bom) {
            throw ValidationException::withMessages(['bom_id' => 'Select an active bill of materials.']);
        }

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

        if ($bom->isMultiStage()) {
            foreach ($bom->stages as $stage) {
                $order->stages()->create([
                    'bom_stage_id' => $stage->id,
                    'sort_order'   => $stage->sort_order,
                    'name'         => $stage->name,
                    'status'       => 'pending',
                ]);
            }
        }

        $order = $order->load([
            'outputProduct:id,sku,name', 'bom:id,name', 'location:id,name',
            'supervisor:id,name', 'stages',
        ]);

        $this->activity->log(
            $companyId,
            $userId,
            'create',
            'production',
            'production_order',
            $order->id,
            "Production {$order->order_no} created",
            [
                'bom_id'          => $order->bom_id,
                'output_quantity' => (float) $order->output_quantity,
                'supervisor_id'   => $order->supervisor_id,
            ],
        );

        return $order;
    }

    public function updateOrder(ProductionOrder $order, array $data, ?int $userId = null): ProductionOrder
    {
        if (! $order->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only draft orders can be edited.']);
        }

        $bom = Bom::forCompany($order->company_id)->where('is_active', true)->find($data['bom_id']);
        if (! $bom) {
            throw ValidationException::withMessages(['bom_id' => 'Select an active bill of materials.']);
        }

        $order->update([
            'bom_id'            => $bom->id,
            'output_product_id' => $bom->product_id,
            'location_id'       => $data['location_id'] ?? null,
            'supervisor_id'     => $data['supervisor_id'] ?? null,
            'order_date'        => $data['order_date'],
            'output_quantity'   => $data['output_quantity'],
            'notes'             => $data['notes'] ?? null,
        ]);

        $this->activity->log(
            $order->company_id,
            $userId,
            'update',
            'production',
            'production_order',
            $order->id,
            "Production {$order->order_no} updated",
            ['output_quantity' => (float) $order->output_quantity],
        );

        return $order->load(['outputProduct:id,sku,name', 'bom:id,name', 'location:id,name', 'supervisor:id,name']);
    }

    public function complete(ProductionOrder $order, ?int $userId = null): ProductionOrder
    {
        if ($order->isCompleted()) {
            return $order->load(['items', 'outputProduct:id,sku,name', 'bom:id,name', 'batches', 'location:id,name', 'supervisor:id,name']);
        }

        if (! $order->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only draft orders can be completed in one step.']);
        }

        if ($order->isMultiStage()) {
            throw ValidationException::withMessages(['status' => 'Complete each production stage individually.']);
        }

        $bom = Bom::forCompany($order->company_id)->with('items')->find($order->bom_id);
        if (! $bom || $bom->items->isEmpty()) {
            throw ValidationException::withMessages(['bom' => 'This order has no bill of materials to consume.']);
        }

        return DB::transaction(function () use ($order, $bom, $userId) {
            $inputCost = $this->consumeMaterials($order, $bom, (float) $order->output_quantity, null, $userId);

            return $this->finalizeOrder($order, $inputCost, $userId);
        });
    }

    public function startStage(ProductionOrder $order, ProductionOrderStage $stage, ?int $userId = null): ProductionOrderStage
    {
        if (! $order->isMultiStage()) {
            throw ValidationException::withMessages(['stage' => 'This order does not use staged production.']);
        }

        if ($order->isCompleted() || $order->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'This production order is no longer active.']);
        }

        if ($stage->production_order_id !== $order->id) {
            throw ValidationException::withMessages(['stage' => 'Stage does not belong to this order.']);
        }

        if (! $stage->isPending()) {
            throw ValidationException::withMessages(['stage' => 'Only pending stages can be started.']);
        }

        $previous = $order->stages()->where('sort_order', '<', $stage->sort_order)->orderByDesc('sort_order')->first();
        if ($previous && ! $previous->isCompleted()) {
            throw ValidationException::withMessages(['stage' => 'Complete the previous stage first.']);
        }

        $stage->update([
            'status'     => 'in_progress',
            'started_at' => now(),
        ]);

        if ($order->isDraft()) {
            $order->update(['status' => 'in_progress']);
        }

        $this->activity->log(
            $order->company_id,
            $userId,
            'start_stage',
            'production',
            'production_order_stage',
            $stage->id,
            "Stage \"{$stage->name}\" started on {$order->order_no}",
            ['production_order_id' => $order->id, 'sort_order' => $stage->sort_order],
        );

        return $stage->fresh(['supervisor:id,name']);
    }

    public function completeStage(ProductionOrder $order, ProductionOrderStage $stage, ?int $userId = null): ProductionOrder
    {
        if ($stage->production_order_id !== $order->id) {
            throw ValidationException::withMessages(['stage' => 'Stage does not belong to this order.']);
        }

        if (! $stage->isInProgress()) {
            throw ValidationException::withMessages(['stage' => 'Only in-progress stages can be completed.']);
        }

        $bom = Bom::forCompany($order->company_id)->with('items')->find($order->bom_id);
        if (! $bom) {
            throw ValidationException::withMessages(['bom' => 'Bill of materials not found.']);
        }

        return DB::transaction(function () use ($order, $stage, $bom, $userId) {
            $stageCost = $this->consumeMaterials(
                $order,
                $bom,
                (float) $order->output_quantity,
                $stage->bom_stage_id,
                $userId,
                $stage,
            );

            $stage->update([
                'status'        => 'completed',
                'completed_at'  => now(),
                'material_cost' => round($stageCost, 2),
            ]);

            $isLast = ! $order->stages()->where('sort_order', '>', $stage->sort_order)->exists();

            if ($isLast) {
                $totalInputCost = (float) $order->stages()->sum('material_cost');

                return $this->finalizeOrder($order, $totalInputCost, $userId);
            }

            $this->activity->log(
                $order->company_id,
                $userId,
                'complete_stage',
                'production',
                'production_order_stage',
                $stage->id,
                "Stage \"{$stage->name}\" completed on {$order->order_no}",
                ['material_cost' => round($stageCost, 2)],
            );

            return $order->refresh()->load([
                'items', 'stages.supervisor:id,name', 'outputProduct:id,sku,name',
                'bom:id,name', 'batches', 'location:id,name', 'supervisor:id,name',
            ]);
        });
    }

    private function consumeMaterials(
        ProductionOrder $order,
        Bom $bom,
        float $outputQuantity,
        ?string $bomStageId,
        ?int $userId,
        ?ProductionOrderStage $orderStage = null,
    ): float {
        $planRows = $this->buildConsumptionPlan($bom, $outputQuantity, $order->company_id, lock: true, bomStageId: $bomStageId);

        if (empty($planRows)) {
            throw ValidationException::withMessages(['items' => 'No materials are configured for this stage.']);
        }

        foreach ($planRows as $row) {
            if (! $row['sufficient']) {
                throw ValidationException::withMessages([
                    'items' => "Not enough {$row['product_name']}: {$row['available_stock']} available, {$row['required_qty']} required.",
                ]);
            }
        }

        $inputCost = 0.0;
        foreach ($planRows as $row) {
            $product = Product::forCompany($order->company_id)->lockForUpdate()->find($row['product_id']);
            if (! $product) {
                continue;
            }
            $needed = (float) $row['required_qty'];
            $unitCost = (float) $product->cost_price;
            $this->inventory->post(
                product: $product, direction: 'out', qty: $needed, unitCost: $unitCost,
                referenceType: 'production', referenceId: $order->id,
                note: "Production {$order->order_no}".($orderStage ? " · {$orderStage->name}" : ''),
                userId: $userId,
            );
            $product->save();
            $lineCost = round($needed * $unitCost, 2);
            $inputCost += $lineCost;
            $order->items()->create([
                'production_order_stage_id' => $orderStage?->id,
                'component_product_id'      => $product->id,
                'product_name'              => $product->name,
                'qty'                       => $needed,
                'unit_cost'                 => $unitCost,
                'line_cost'                 => $lineCost,
            ]);
        }

        return $inputCost;
    }

    private function finalizeOrder(ProductionOrder $order, float $inputCost, ?int $userId): ProductionOrder
    {
        $outQty = (float) $order->output_quantity;
        $unitCost = $outQty > 0 ? round($inputCost / $outQty, 4) : 0.0;
        $output = Product::forCompany($order->company_id)->lockForUpdate()->find($order->output_product_id);
        if ($output) {
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

        $this->activity->log(
            $order->company_id,
            $userId,
            'complete',
            'production',
            'production_order',
            $order->id,
            "Production {$order->order_no} completed",
            [
                'total_input_cost' => round($inputCost, 2),
                'output_unit_cost' => $unitCost,
                'output_quantity'  => $outQty,
            ],
        );

        return $order->refresh()->load([
            'items', 'stages.supervisor:id,name', 'outputProduct:id,sku,name',
            'bom:id,name', 'batches', 'location:id,name', 'supervisor:id,name',
        ]);
    }

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

                $output = Product::forCompany($order->company_id)->lockForUpdate()->find($order->output_product_id);
                if ($output) {
                    $this->inventory->post(
                        product: $output, direction: 'out', qty: (float) $order->output_quantity,
                        unitCost: (float) $order->output_unit_cost, referenceType: 'production-cancel',
                        referenceId: $order->id, note: "Reversal of {$order->order_no}", userId: $userId,
                    );
                    $output->save();
                }
                foreach ($order->items as $item) {
                    $this->reverseConsumedItem($order, $item, $userId);
                }
            } elseif ($order->isInProgress()) {
                $order->loadMissing('items');
                foreach ($order->items as $item) {
                    $this->reverseConsumedItem($order, $item, $userId);
                }
            }

            if ($order->isMultiStage()) {
                $order->stages()->update([
                    'status'        => 'pending',
                    'started_at'    => null,
                    'completed_at'  => null,
                    'material_cost' => 0,
                ]);
            }

            $order->update(['status' => 'cancelled']);

            $this->activity->log(
                $order->company_id,
                $userId,
                'cancel',
                'production',
                'production_order',
                $order->id,
                "Production {$order->order_no} cancelled",
            );

            return $order->refresh();
        });
    }

    private function reverseConsumedItem(ProductionOrder $order, $item, ?int $userId): void
    {
        if (! $item->component_product_id) {
            return;
        }
        $product = Product::forCompany($order->company_id)->lockForUpdate()->find($item->component_product_id);
        if (! $product) {
            return;
        }
        $this->inventory->post(
            product: $product, direction: 'in', qty: (float) $item->qty,
            unitCost: (float) $item->unit_cost, referenceType: 'production-cancel',
            referenceId: $order->id, note: "Reversal of {$order->order_no}", userId: $userId,
        );
        $product->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildConsumptionPlan(
        Bom $bom,
        float $outputQuantity,
        int|string $companyId,
        bool $lock,
        ?string $bomStageId = null,
    ): array {
        $factor = (float) $bom->output_qty > 0 ? $outputQuantity / (float) $bom->output_qty : 0.0;
        $rows = [];

        $bomItems = $bom->items;
        if ($bomStageId !== null) {
            $bomItems = $bomItems->where('bom_stage_id', $bomStageId);
        }

        foreach ($bomItems as $bomItem) {
            $needed = $this->componentQtyNeeded($bomItem, $factor);
            $query = Product::forCompany($companyId);
            if ($lock) {
                $query = $query->lockForUpdate();
            }
            $product = $query->find($bomItem->component_product_id);
            if (! $product) {
                continue;
            }
            $available = (float) $product->current_stock;
            $unitCost = (float) $product->cost_price;
            $rows[] = [
                'product_id'      => $product->id,
                'product_name'    => $product->name,
                'recipe_qty'      => round((float) $bomItem->qty * $factor, 3),
                'wastage_pct'     => (float) ($bomItem->wastage_pct ?? 0),
                'required_qty'    => $needed,
                'available_stock' => $available,
                'unit_cost'       => $unitCost,
                'line_cost'       => round($needed * $unitCost, 2),
                'sufficient'      => $available >= $needed,
            ];
        }

        return $rows;
    }

    private function componentQtyNeeded(BomItem $bomItem, float $factor): float
    {
        $base = (float) $bomItem->qty * $factor;
        $wastage = max(0.0, (float) ($bomItem->wastage_pct ?? 0));

        return round($base * (1 + ($wastage / 100)), 3);
    }

    private function nextOrderNo(int|string $companyId): string
    {
        $count = ProductionOrder::withTrashed()->forCompany($companyId)->count();

        return 'PRD-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
