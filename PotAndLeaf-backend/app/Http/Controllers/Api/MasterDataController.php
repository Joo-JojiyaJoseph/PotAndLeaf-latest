<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * One controller for the product master lists (categories, units).
 * Each is company-scoped; the {type} segment selects which.
 */
class MasterDataController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    private const TYPES = [
        'categories' => [ProductCategory::class, ['name', 'code', 'description', 'parent_id', 'status'], true],
        'brands'     => [ProductBrand::class, ['name', 'code', 'description', 'status'], false],
        'units'      => [ProductUnit::class, ['name', 'code', 'short_name', 'description', 'status'], false],
    ];

    public function index(Request $request, string $type): JsonResponse
    {
        [$model, , $hasParent] = $this->resolve($type);
        $companyId = $this->listCompanyId($request);
        $headerCompanyId = $this->companyId($request);

        if ($type === 'categories') {
            $this->allowCategoryAccess($request, $headerCompanyId, 'view');
        } else {
            $this->allow($request, "{$type}.view", $headerCompanyId);
        }

        $rows = $model::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('name')
            ->get();
        $parents = $hasParent
            ? $rows->whereNull('parent_id')->pluck('name', 'id')
            : collect();

        return $this->ok($rows->map(fn ($r) => $this->present($r, $type, $parents))->values());
    }

    public function store(Request $request, string $type): JsonResponse
    {
        [$model, , $hasParent] = $this->resolve($type);
        $companyId = $this->companyId($request);

        $data = $this->validated($request, $type, $companyId, $hasParent, null);

        if ($type === 'categories') {
            $this->allow($request, $this->categoryPerm($data['parent_id'] ?? null).'.create', $companyId);
        } else {
            $this->allow($request, "{$type}.create", $companyId);
        }

        if (empty($data['code'])) {
            $data['code'] = $this->nextCode($model, $companyId, $type, $data['parent_id'] ?? null);
        }
        $row = $model::create($data + ['company_id' => $companyId]);

        return $this->created($this->present($row, $type, collect()), ucfirst(rtrim($type, 's')).' created.');
    }

    public function update(Request $request, string $type, string $id): JsonResponse
    {
        [$model, , $hasParent] = $this->resolve($type);
        $companyId = $this->companyId($request);

        $row = $model::where('company_id', $companyId)->findOrFail($id);

        if ($type === 'categories') {
            $this->allow($request, $this->categoryPerm($row->parent_id).'.update', $companyId);
        } else {
            $this->allow($request, "{$type}.update", $companyId);
        }

        $data = $this->validated($request, $type, $companyId, $hasParent, $id);
        unset($data['code']);

        if ($request->user()->is_super_admin && $request->filled('company_id')) {
            $targetCompanyId = $request->input('company_id');
            if ((string) $targetCompanyId !== (string) $companyId) {
                $this->assertMovableToCompany($type, $row);
                $data['company_id'] = $targetCompanyId;
                $request->validate([
                    'company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
                ]);
            }
        }

        $row->update($data);

        return $this->ok($this->present($row->refresh(), $type, collect()), 'Updated.');
    }

    private function assertMovableToCompany(string $type, $row): void
    {
        if ($type === 'categories') {
            $count = \App\Models\Product::query()->where('category_id', $row->id)->count();
            abort_if($count > 0, 422, 'Cannot change company — products are linked to this category.');
        }
    }

    public function destroy(Request $request, string $type, string $id): JsonResponse
    {
        [$model] = $this->resolve($type);
        $companyId = $this->companyId($request);

        $row = $model::where('company_id', $companyId)->findOrFail($id);

        if ($type === 'categories') {
            $this->allow($request, $this->categoryPerm($row->parent_id).'.delete', $companyId);
        } else {
            $this->allow($request, "{$type}.delete", $companyId);
        }

        $row->delete();

        return $this->message('Deleted.');
    }

    private function validated(Request $request, string $type, int|string $companyId, bool $hasParent, ?string $id): array
    {
        $table = match ($type) {
            'categories' => 'product_categories',
            'brands'     => 'product_brands',
            'units'      => 'product_units',
            default      => abort(404),
        };

        $rules = [
            'name'        => [
                'required', 'string', 'max:150',
                Rule::unique($table, 'name')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($id),
            ],
            'code'        => [
                'nullable', 'string', 'max:50',
                Rule::unique($table, 'code')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'status'      => ['nullable', 'in:active,inactive'],
            'company_id'  => ['sometimes', 'integer', Rule::exists('companies', 'id')],
        ];
        if ($type === 'units') {
            $rules['short_name'] = ['nullable', 'string', 'max:20'];
        }
        if ($hasParent) {
            $rules['parent_id'] = [
                'nullable', 'uuid',
                Rule::exists('product_categories', 'id')->where('company_id', $companyId)->whereNull('parent_id'),
            ];
        }
        $data = $request->validate($rules, [
            'name.required'  => 'Name is required.',
            'name.unique'    => 'This name already exists for this company.',
            'code.unique'    => 'This code already exists for this company.',
        ]);
        $data['status'] ??= 'active';
        if ($hasParent && ($data['parent_id'] ?? null) === $id) {
            $data['parent_id'] = null;
        }

        return $data;
    }

    private function categoryPerm(?string $parentId): string
    {
        return filled($parentId) ? 'subcategories' : 'categories';
    }

    private function allowCategoryAccess(Request $request, int|string $companyId, string $action): void
    {
        if ($request->user()->is_super_admin) {
            return;
        }

        abort_unless(
            $request->user()->hasPermission("categories.{$action}", $companyId)
            || $request->user()->hasPermission("subcategories.{$action}", $companyId),
            403
        );
    }

    private function nextCode(string $modelClass, int|string $companyId, string $type, ?string $parentId): string
    {
        $prefix = match ($type) {
            'categories' => $parentId ? 'SUB' : 'CAT',
            'units'      => 'UNIT',
            'brands'     => 'BRD',
            default      => 'MST',
        };
        $count = $modelClass::withTrashed()->where('company_id', $companyId)->count();

        return $prefix.'-'.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }

    private function present($r, string $type, $parents): array
    {
        $out = [
            'id'          => $r->id,
            'company_id'  => $r->company_id,
            'name'        => $r->name,
            'code'        => $r->code,
            'description' => $r->description,
            'status'      => $r->status,
        ];
        if ($type === 'units') {
            $out['short_name'] = $r->short_name;
        }
        if ($type === 'categories') {
            $out['parent_id'] = $r->parent_id;
            $out['parent_name'] = $r->parent_id ? ($parents[$r->parent_id] ?? null) : null;
        }

        return $out;
    }

    private function resolve(string $type): array
    {
        abort_unless(array_key_exists($type, self::TYPES), 404, 'Unknown master type.');

        return self::TYPES[$type];
    }

    private function companyId(Request $request)
    {
        return $request->attributes->get('company')->id;
    }

    private function allow(Request $request, string $permission, int|string $companyId): void
    {
        abort_unless($request->user()->hasPermission($permission, $companyId), 403);
    }
}
