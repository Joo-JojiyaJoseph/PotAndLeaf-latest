<?php

namespace App\Http\Requests\StockVerification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('stock_verifications.create', $this->companyId());
    }

    protected function companyId(): int|string
    {
        return $this->route('current_company')->id;
    }

    public function rules(): array
    {
        $companyId = $this->companyId();

        return [
            'count_date'    => ['required', 'date', \App\Support\Validation\BusinessRules::notFuture('Count date')],
            'location_note' => ['nullable', 'string', 'max:120'],
            'notes'         => ['nullable', 'string', 'max:2000'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.product_id'    => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.counted_qty'   => ['required', 'numeric', 'min:0'],
            'items.*.product_batch_id' => ['nullable', 'uuid', Rule::exists('product_batches', 'id')->where('company_id', $companyId)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $seen = [];
            foreach ((array) $this->input('items', []) as $i => $item) {
                $key = ($item['product_id'] ?? '').'|'.($item['product_batch_id'] ?? '');
                if (isset($seen[$key])) {
                    $v->errors()->add("items.$i.product_id", 'The same product / batch is listed twice.');
                }
                $seen[$key] = true;
                if (! empty($item['product_batch_id'])) {
                    $ok = \App\Models\ProductBatch::query()->whereKey($item['product_batch_id'])->where('product_id', $item['product_id'] ?? null)->exists();
                    if (! $ok) {
                        $v->errors()->add("items.$i.product_batch_id", 'This batch does not belong to the product.');
                    }
                }
            }
        });
    }
}
