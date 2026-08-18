<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreCompanyRequest;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
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

        $companies = Company::query()->where('is_protected', false)->withCount('users')->orderBy('name')->get();

        return $this->ok(CompanyResource::collection($companies));
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $data = $request->validated();
        unset($data['photo']);
        if (empty($data['code'])) {
            $data['code'] = $this->nextCompanyCode();
        }
        $locations = $this->extractLocations($data); // never a companies column

        // A code from a soft-deleted company still occupies the unique index.
        // Re-adding that code should reactivate the old record (with the new
        // details) rather than crash on a duplicate key.
        $trashed = Company::onlyTrashed()->where('code', $data['code'])->first();
        if ($trashed) {
            $trashed->restore();
            $trashed->fill($data)->save();
            $this->syncLocations($trashed, $locations);

            return $this->created(new CompanyResource($trashed), 'Company created.');
        }

        $company = Company::create($data);
        $this->syncLocations($company, $locations);

        return $this->created(new CompanyResource($company), 'Company created.');
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        return $this->ok(new CompanyResource($company));
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();
        unset($data['photo'], $data['code']);
        $locations = $this->extractLocations($data);

        if (array_key_exists('logo', $data)) {
            $data['logo'] = MediaStorage::replace($company->logo, $data['logo']);
        }

        $company->update($data);
        $this->syncLocations($company, $locations);

        return $this->ok(new CompanyResource($company->fresh()), 'Company updated.');
    }

    /** Pull the free-text "locations" out of the payload — it's not a companies column. */
    private function extractLocations(array &$data): ?array
    {
        if (! array_key_exists('locations', $data)) {
            return null;
        }
        $raw = $data['locations'];
        unset($data['locations']);
        if (blank($raw)) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', (string) $raw))
            ->map(fn ($l) => trim($l))->filter()->unique()->values()->all();
    }

    /** Create any new godown/shop lines as Location records (never destructive). */
    private function syncLocations(Company $company, ?array $names): void
    {
        if ($names === null) {
            return;
        }
        $existing = $company->locations()->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $first = $company->locations()->count() === 0;
        foreach ($names as $name) {
            if (in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }
            $company->locations()->create([
                'name'       => $name,
                'code'       => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'LOC', 0, 6)).'-'.strtoupper(substr((string) \Illuminate\Support\Str::uuid(), 0, 4)),
                'type'       => 'godown',
                'is_default' => $first,
                'is_active'  => true,
            ]);
            $first = false;
        }
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
