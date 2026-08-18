<?php

namespace App\Http\Controllers;

use App\Models\ProductBrand;
use App\Support\Lookup\LookupController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductBrandController extends LookupController
{
    protected function model(): string
    {
        return ProductBrand::class;
    }

    protected function key(): string
    {
        return 'brands';
    }

    protected function permission(): string
    {
        return 'brands';
    }

    protected function rules(Request $request, ?string $id): array
    {
        $companyId = $request->route('current_company')->id;

        return [
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('product_brands', 'code')
                    ->where('company_id', $companyId)->whereNull('deleted_at')->ignore($id),
            ],
            'name'        => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status'      => ['required', 'in:active,inactive'],
        ];
    }

    protected function transform(Model $model): array
    {
        return [
            'id'          => $model->id,
            'code'        => $model->code,
            'name'        => $model->name,
            'description' => $model->description,
            'status'      => $model->status,
        ];
    }
}
