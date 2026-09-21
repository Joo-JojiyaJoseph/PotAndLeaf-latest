<?php

namespace App\Http\Requests\Transfer;

use App\Models\Product;
use App\Services\LocationStockService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('transfers.create', $this->route('current_company')->id);
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;
        $isIntra = $this->input('transfer_type') === 'intra_company';
        $sourceId = (int) ($this->input('from_company_id') ?: $companyId);
        $loc = fn () => Rule::exists('locations', 'id')->where('company_id', $sourceId)->where('is_active', true);

        return [
            'transfer_type'        => ['nullable', Rule::in(['inter_company', 'intra_company'])],
            'from_company_id'      => ['nullable', 'integer', Rule::exists('companies', 'id')->where('is_active', true)],
            'to_company_id'        => [$isIntra ? 'nullable' : 'required', 'integer', Rule::exists('companies', 'id')->where('is_active', true), Rule::notIn([$sourceId])],
            'from_location_id'     => [$isIntra ? 'required' : 'nullable', 'uuid', $loc()],
            'to_location_id'       => [$isIntra ? 'required' : 'nullable', 'uuid', $loc(), Rule::notIn([$this->input('from_location_id')])],
            'transfer_date'        => ['required', 'date'],
            'notes'                => ['nullable', 'string', 'max:1000'],
            'confirm'              => ['nullable', 'boolean'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $sourceId)],
            'items.*.product_batch_id' => ['nullable', 'uuid', Rule::exists('product_batches', 'id')->where('company_id', $sourceId)],
            'items.*.qty'          => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $companyId = $this->route('current_company')->id;
            $isIntra = $this->input('transfer_type') === 'intra_company';
            $sourceId = (int) ($this->input('from_company_id') ?: $companyId);
            if ($isIntra && $sourceId !== (int) $companyId) {
                $validator->errors()->add('from_company_id', 'Location transfers must stay in the current company.');

                return;
            }
            $items = collect($this->input('items', []));
            $locationStock = app(LocationStockService::class);

            $byProduct = $items->groupBy('product_id')->map(fn ($rows) => $rows->sum(fn ($r) => (float) ($r['qty'] ?? 0)));

            foreach ($byProduct as $productId => $requestedQty) {
                $product = Product::forCompany($sourceId)->find($productId);
                if (! $product) {
                    continue;
                }

                if ($isIntra) {
                    $available = $locationStock->available((string) $this->input('from_location_id'), (string) $productId);
                    if ($requestedQty > $available + 0.0001) {
                        $validator->errors()->add(
                            'items',
                            "Not enough stock at source location for {$product->name}: {$available} available, {$requestedQty} requested.",
                        );
                    }
                } elseif ($requestedQty > (float) $product->current_stock + 0.0001) {
                    $validator->errors()->add(
                        'items',
                        "Not enough stock for {$product->name}: {$product->current_stock} available, {$requestedQty} requested.",
                    );
                }
            }
        });
    }
}
