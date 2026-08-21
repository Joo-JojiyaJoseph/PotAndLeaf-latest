<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreCompanyRequest;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Api\ApiResponse;
use App\Support\Media\MediaStorage;
use App\Support\ProtectedRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Company management — HO super-admin surface (not company-scoped). */
class CompanyController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $search = trim((string) $request->query('search', ''));

        $companies = Company::query()
            ->where('is_protected', false)
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return $this->ok(CompanyResource::collection($companies));
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $data = $request->validated();
        unset($data['photo']);
        if (array_key_exists('logo', $data)) {
            $data['logo'] = MediaStorage::replace(null, $data['logo']);
        }
        if (empty($data['code'])) {
            $data['code'] = $this->nextCompanyCode();
        }
        // `locations` is a column on the companies table — keep it in $data so
        // it persists with the row. (Do NOT strip it out.)

        // A code from a soft-deleted company still occupies the unique index.
        // Re-adding that code should reactivate the old record (with the new
        // details) rather than crash on a duplicate key.
        $trashed = Company::onlyTrashed()->where('code', $data['code'])->first();
        if ($trashed) {
            $trashed->restore();
            $trashed->fill($data)->save();

            return $this->created(new CompanyResource($trashed), 'Company created.');
        }

        $company = Company::create($data);

        return $this->created(new CompanyResource($company), 'Company created.');
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $company->loadCount('users');

        return $this->ok(CompanyResource::withStatistics($company, $this->companyStatistics($company)));
    }

    /** @return array<string, int> */
    private function companyStatistics(Company $company): array
    {
        $companyId = $company->id;
        $memberIds = User::query()
            ->where('is_super_admin', false)
            ->whereHas('companies', fn ($q) => $q->whereKey($companyId))
            ->pluck('id');

        return [
            'users_total'         => $memberIds->count(),
            'users_active'        => User::query()->whereIn('id', $memberIds)->where('is_active', true)->count(),
            'users_inactive'      => User::query()->whereIn('id', $memberIds)->where('is_active', false)->count(),
            'products_total'      => Product::forCompany($companyId)->count(),
            'categories_total'    => ProductCategory::query()->where('company_id', $companyId)->whereNull('parent_id')->count(),
            'subcategories_total' => ProductCategory::query()->where('company_id', $companyId)->whereNotNull('parent_id')->count(),
            'suppliers_total'     => Supplier::forCompany($companyId)->count(),
            'purchases_total'     => Purchase::forCompany($companyId)->count(),
        ];
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();
        unset($data['photo'], $data['code']);

        if (array_key_exists('logo', $data)) {
            $data['logo'] = MediaStorage::replace($company->logo, $data['logo']);
        }

        // `locations` stays in $data and is saved as a company column.
        $company->update($data);

        return $this->ok(new CompanyResource($company->fresh()), 'Company updated.');
    }

    public function destroy(Request $request, Company $company): JsonResponse
    {
        $this->ensureSuperAdmin($request);
        abort_if(ProtectedRecords::isProtectedCompany($company), 403, 'This company cannot be deleted.');

        $company->delete();

        return $this->message('Company deleted.');
    }

    public function toggleStatus(Request $request, Company $company): JsonResponse
    {
        $this->ensureSuperAdmin($request);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        abort_if(! $data['is_active'] && ProtectedRecords::isProtectedCompany($company), 403, 'This company cannot be deactivated.');

        $company->update(['is_active' => $data['is_active']]);

        return $this->ok(['id' => $company->id, 'is_active' => (bool) $company->is_active], 'Status updated.');
    }

    private function ensureSuperAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_super_admin, 403, 'Only HO super admins can manage companies.');
    }

    private function nextCompanyCode(): string
    {
        $count = Company::withTrashed()->count();

        return 'CMP-'.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }
}