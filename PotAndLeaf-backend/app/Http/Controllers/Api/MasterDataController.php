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
 * One controller for the product master lists (categories, brands, units).
 * Each is company-scoped; the {type} segment selects which.
 */
class MasterDataController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    private const TYPES = [
        'categories' => [ProductCategory::class, 'categories', ['name', 'code', 'description', 'parent_id', 'status'], true],
        'brands'     => [ProductBrand::class, 'brands', ['name', 'code', 'description', 'status'], false],
        'units'      => [ProductUnit::class, 'units', ['name', 'code', 'short_name', 'description', 'status'], false],
    ];

    public function index(Request $request, string $type): JsonResponse
    {
        [$model, $perm, , $hasParent] = $this->resolve($type);
        $companyId = $this->listCompanyId($request);
        $this->allow($request, "{$perm}.view", $this->companyId($request));

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
        [$model, $perm, , $hasParent] = $this->resolve($type);
        $companyId = $this->companyId($request);
        $this->allow($request, "{$perm}.create", $companyId);

        $data = $this->validated($request, $type, $companyId, $hasParent, null);
        if (empty($data['code'])) {
            $data['code'] = $this->nextCode($model, $companyId, $type, $data['parent_id'] ?? null);
        }
        $row = $model::create($data + ['company_id' => $companyId]);

        return $this->created($this->present($row, $type, collect()), ucfirst(rtrim($type, 's')).' created.');
    }

    public function update(Request $request, string $type, string $id): JsonResponse
    {
        [$model, $perm, , $hasParent] = $this->resolve($type);
        $companyId = $this->companyId($request);
        $this->allow($request, "{$perm}.update", $companyId);

        $row = $model::where('company_id', $companyId)->findOrFail($id);
        $data = $this->validated($request, $type, $companyId, $hasParent, $id);
        unset($data['code']); // codes are auto-generated and immutable
        $row->update($data);

        return $this->ok($this->present($row->refresh(), $type, collect()), 'Updated.');
    }

    public function destroy(Request $request, string $type, string $id): JsonResponse
    {
        [$model, $perm] = $this->resolve($type);
        $companyId = $this->companyId($request);
        $this->allow($request, "{$perm}.delete", $companyId);

        $model::where('company_id', $companyId)->findOrFail($id)->delete();

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
