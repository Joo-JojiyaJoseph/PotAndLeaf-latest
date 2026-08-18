<?php

namespace App\Actions\SalesReturns;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Repositories\Contracts\SalesReturnRepositoryInterface;
use App\Support\Sales\SaleCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draft credit note against a confirmed sale. Rates/GST come from the original
 * sale lines; only return qty is user-supplied and capped at still-returnable.
 */
class CreateSalesReturn
{
    public function __construct(
        private readonly SalesReturnRepositoryInterface $returns,
        private readonly SaleCalculator $calculator,
    ) {}

    /** @param array<string,mixed> $data */
    public function handle(int|string $companyId, array $data, ?int $userId = null): SalesReturn
    {
        $sale = Sale::forCompany($companyId)->with('items')->find($data['sale_id']);

        if (! $sale || ! $sale->isConfirmed()) {
            throw ValidationException::withMessages([
                'sale_id' => 'You can only return against a confirmed sale.',
            ]);
        }

        $itemsById = $sale->items->keyBy('id');
        $alreadyReturned = $this->returns->returnedQtyBySaleItem($sale->id);

        $calcInput = [];
        $meta = [];
        foreach ($data['items'] as $row) {
            $orig = $itemsById->get($row['sale_item_id']);
            if (! $orig) {
                throw ValidationException::withMessages([
                    'items' => 'A line does not belong to the selected sale.',
                ]);
            }

            $remaining = (float) $orig->qty - (float) ($alreadyReturned[$orig->id] ?? 0);
            $qty = (float) $row['qty'];
            if ($qty > $remaining + 1e-6) {
                throw ValidationException::withMessages([
                    'items' => "Cannot return more than {$remaining} of {$orig->product_name}.",
                ]);
            }

            // Pro-rate original line discount by qty share.
            $origDiscount = (float) $orig->discount;
            $lineDiscount = (float) $orig->qty > 0
                ? round($origDiscount * ($qty / (float) $orig->qty), 2)
                : 0;

            $calcInput[] = [
                'qty' => $qty,
                'rate' => (float) $orig->rate,
                'discount' => $lineDiscount,
                'gst_rate' => (float) $orig->gst_rate,
            ];
            $meta[] = $orig;
        }

        $computed = $this->calculator->compute($calcInput, (bool) $sale->is_interstate);

        return DB::transaction(function () use ($companyId, $data, $sale, $computed, $meta, $calcInput) {
            $t = $computed['totals'];
            $return = $this->returns->create([
                'company_id'    => $companyId,
                'sale_id'       => $sale->id,
                'customer_id'   => $sale->customer_id,
                'location_id'   => $sale->location_id,
                'return_no'     => $this->returns->nextReturnNo($companyId),
                'return_date'   => $data['return_date'],
                'is_interstate' => (bool) $sale->is_interstate,
                'reason'        => $data['reason'] ?? null,
                'notes'         => $data['notes'] ?? null,
                'subtotal'      => $t['subtotal'],
                'tax_total'     => $t['tax_total'],
                'round_off'     => $t['round_off'],
                'grand_total'   => $t['grand_total'],
                'status'        => 'draft',
            ]);

            $productCosts = Product::forCompany($companyId)
                ->whereIn('id', collect($meta)->pluck('product_id')->filter())
                ->pluck('cost_price', 'id');

            $rows = [];
            foreach ($computed['lines'] as $i => $line) {
                $orig = $meta[$i];
                $inp = $calcInput[$i];
                $rows[] = [
                    'sale_item_id'  => $orig->id,
                    'product_id'    => $orig->product_id,
                    'product_name'  => $orig->product_name,
                    'hsn_code'      => $orig->hsn_code,
                    'qty'           => $inp['qty'],
                    'rate'          => $inp['rate'],
                    'discount'      => $inp['discount'],
                    'gst_rate'      => $inp['gst_rate'],
                    'taxable_value' => $line['taxable_value'],
                    'cgst_amount'   => $line['cgst_amount'],
                    'sgst_amount'   => $line['sgst_amount'],
                    'igst_amount'   => $line['igst_amount'],
                    'line_total'    => $line['line_total'],
                    'unit_cost'     => (float) ($productCosts[$orig->product_id] ?? 0),
                ];
            }
            $return->items()->createMany($rows);

            return $return->load(['customer', 'sale:id,sale_no', 'items']);
        });
    }
}
