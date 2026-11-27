<?php

namespace App\Http\Requests\Transfer;

use App\Models\Location;
use App\Models\Product;
use App\Services\LocationStockService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A stock transfer moves stock between two locations (branch or godown) of ONE company:
 * godown to branch, branch to godown, or branch to branch.
 */
class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('transfers.create', $this->route('current_company')->id);
    }

    public function messages(): array
    {
        return [
            'transfer_type.in' => 'Stock can only move between the branches and godowns of one company, not between companies.',
        ];
    }

    public function rules(): array
    {
        $companyId = $this->route('current_company')->id;
        $loc = fn () => Rule::exists('locations', 'id')->where('company_id', $companyId)->where('is_active', true);

        return [
            'transfer_type'        => ['nullable', Rule::in(['intra_company'])],
            'from_location_id'     => ['required', 'uuid', $loc()],
            'to_location_id'       => ['required', 'uuid', $loc(), Rule::notIn([$this->input('from_location_id')])],
            'transfer_date'        => ['required', 'date', \App\Support\Validation\BusinessRules::notFuture('Transfer date')],
            'notes'                => ['nullable', 'string', 'max:1000'],
            'confirm'              => ['nullable', 'boolean'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.product_batch_id' => ['nullable', 'uuid', Rule::exists('product_batches', 'id')->where('company_id', $companyId)],
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
            $from = Location::forCompany($companyId)->find($this->input('from_location_id'));
            $to = Location::forCompany($companyId)->find($this->input('to_location_id'));
            if ($from && $to && $from->type === 'godown' && $to->type === 'godown') {
                $validator->errors()->add('to_location_id', 'Stock cannot move from one godown to another. Send it to a branch first.');

                return;
            }

            // A godown user only moves stock into or out of their own godown.
            $user = $this->user();
            if ($user->isGodownUser($companyId)) {
                $mine = (string) $user->locationIdFor($companyId);
                if ((string) $from?->id !== $mine && (string) $to?->id !== $mine) {
                    $validator->errors()->add('from_location_id', 'You can only move stock into or out of your own godown.');

                    return;
                }
            }

            $locationStock = app(LocationStockService::class);
            $byProduct = collect($this->input('items', []))->groupBy('product_id')
                ->map(fn ($rows) => $rows->sum(fn ($r) => (float) ($r['qty'] ?? 0)));

            foreach ($byProduct as $productId => $requestedQty) {
                $product = Product::forCompany($companyId)->find($productId);
                if (! $product) {
                    continue;
                }
                $available = $locationStock->available((string) $this->input('from_location_id'), (string) $productId);
                if ($requestedQty > $available + 0.0001) {
                    $validator->errors()->add(
                        'items',
                        "Not enough stock of {$product->name} at {$from?->name}: {$available} available, {$requestedQty} requested.",
                    );
                }
            }
        });
    }
}
