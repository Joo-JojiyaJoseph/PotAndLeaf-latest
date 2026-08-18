<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use App\Support\Lookup\LookupController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductCategoryController extends LookupController
{
    protected function model(): string
    {
        return ProductCategory::class;
    }

    protected function key(): string
    {
        return 'categories';
    }

    protected function permission(): string
    {
        return 'categories';
    }

    protected function rules(Request $request, ?string $id): array
    {
        $companyId = $request->route('current_company')->id;

        return [
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('product_categories', 'code')
                    ->where('company_id', $companyId)->whereNull('deleted_at')->ignore($id),
            ],
            'name'        => ['required', 'string', 'max:191'],
            'parent_id'   => ['nullable', 'uuid'],
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
            'parent_id'   => $model->parent_id,
            'description' => $model->description,
            'status'      => $model->status,
        ];
    }
}
