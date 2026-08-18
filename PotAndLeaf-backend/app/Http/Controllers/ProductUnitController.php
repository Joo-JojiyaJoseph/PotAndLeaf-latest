<?php

namespace App\Http\Controllers;

use App\Models\ProductUnit;
use App\Support\Lookup\LookupController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductUnitController extends LookupController
{
    protected function model(): string
    {
        return ProductUnit::class;
    }

    protected function key(): string
    {
        return 'units';
    }

    protected function permission(): string
    {
        return 'units';
    }

    protected function rules(Request $request, ?string $id): array
    {
        $companyId = $request->route('current_company')->id;

        return [
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('product_units', 'code')
                    ->where('company_id', $companyId)->whereNull('deleted_at')->ignore($id),
            ],
            'name'        => ['required', 'string', 'max:191'],
            'short_name'  => ['nullable', 'string', 'max:20'],
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
            'short_name'  => $model->short_name,
            'description' => $model->description,
            'status'      => $model->status,
        ];
    }
}
