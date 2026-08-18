<?php

namespace App\Actions\PurchaseReturns;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Repositories\Contracts\PurchaseReturnRepositoryInterface;
use App\Support\Purchasing\PurchaseCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds a draft return against a confirmed purchase. Rates, GST and product
 * details are pulled from the original purchase lines (not the client) so the
 * debit note always mirrors what was actually bought; only the return quantity
 * is user-supplied, and it's capped at the still-returnable amount.
 */
class CreatePurchaseReturn
{
    public function __construct(
        private readonly PurchaseReturnRepositoryInterface $returns,
        private readonly PurchaseCalculator $calculator,
    ) {}

    /** @param array<string,mixed> $data */
    public function handle(int|string $companyId, array $data, ?int $userId = null): PurchaseReturn
    {
        $purchase = Purchase::forCompany($companyId)->with('items')->find($data['purchase_id']);

        if (! $purchase || ! $purchase->isConfirmed()) {
            throw ValidationException::withMessages([
                'purchase_id' => 'You can only return against a confirmed purchase.',
            ]);
        }

        $itemsById = $purchase->items->keyBy('id');
        $alreadyReturned = $this->returns->returnedQtyByPurchaseItem($purchase->id);

        $calcInput = [];
        $meta = [];
        foreach ($data['items'] as $row) {
            $orig = $itemsById->get($row['purchase_item_id']);
            if (! $orig) {
                throw ValidationException::withMessages([
                    'items' => 'A line does not belong to the selected purchase.',
                ]);
            }

            $remaining = (float) $orig->qty - (float) ($alreadyReturned[$orig->id] ?? 0);
            $qty = (float) $row['qty'];
            if ($qty > $remaining + 1e-6) {
                throw ValidationException::withMessages([
                    'items' => "Cannot return more than {$remaining} of {$orig->product_name}.",
                ]);
            }

            $calcInput[] = ['qty' => $qty, 'rate' => (float) $orig->rate, 'discount' => 0, 'gst_rate' => (float) $orig->gst_rate];
            $meta[] = $orig;
        }

        $computed = $this->calculator->compute($calcInput, (bool) $purchase->is_interstate, 0);

        return DB::transaction(function () use ($companyId, $data, $purchase, $computed, $meta) {
            $return = $this->returns->create([
                'company_id'       => $companyId,
                'purchase_id'   => $purchase->id,
                'supplier_id'   => $purchase->supplier_id,
                'return_no'     => $this->returns->nextReturnNo($companyId),
                'return_date'   => $data['return_date'],
                'is_interstate' => (bool) $purchase->is_interstate,
                'reason'        => $data['reason'] ?? null,
                'notes'         => $data['notes'] ?? null,
                'subtotal'      => $computed['totals']['subtotal'],
                'tax_total'     => $computed['totals']['tax_total'],
                'grand_total'   => $computed['totals']['grand_total'],
                'status'        => 'draft',
            ]);

            // Resolve each line's batch from its purchase line (1:1), so the
            // return decrements the exact lot it came from.
            $batchByItem = \App\Models\ProductBatch::forCompany($companyId)
                ->whereIn('purchase_item_id', collect($computed['items'])->map(fn ($l, $i) => $meta[$i]->id)->all())
                ->pluck('id', 'purchase_item_id');

            $rows = [];
            foreach ($computed['items'] as $i => $line) {
                $orig = $meta[$i];
                $rows[] = [
                    'purchase_item_id' => $orig->id,
                    'product_id'       => $orig->product_id,
                    'product_batch_id' => $batchByItem[$orig->id] ?? null,
                    'product_name'     => $orig->product_name,
                    'hsn_code'         => $orig->hsn_code,
                    'qty'              => $line['qty'],
                    'rate'             => $line['rate'],
                    'gst_rate'         => $line['gst_rate'],
                    'taxable_value'    => $line['taxable_value'],
                    'cgst_amount'      => $line['cgst_amount'],
                    'sgst_amount'      => $line['sgst_amount'],
                    'igst_amount'      => $line['igst_amount'],
                    'line_total'       => $line['line_total'],
                    'unit_cost'        => $orig->landed_unit_cost,
                ];
            }
            $return->items()->createMany($rows);

            return $return->load(['supplier', 'purchase:id,purchase_no', 'items']);
        });
    }
}
