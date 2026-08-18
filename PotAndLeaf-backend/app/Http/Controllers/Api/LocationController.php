<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\StoreLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function index(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'locations.view');

        return $this->ok(LocationResource::collection(
            Location::forCompany($company->id)->orderByDesc('is_default')->orderBy('name')->get()
        ));
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $location = $this->save($company->id, null, $request->validated());

        return $this->created(new LocationResource($location), 'Location created.');
    }

    public function update(StoreLocationRequest $request, Location $location): JsonResponse
    {
        $this->sameCompany($request, $location);
        $updated = $this->save($this->company($request)->id, $location, $request->validated());

        return $this->ok(new LocationResource($updated), 'Location updated.');
    }

    public function destroy(Request $request, Location $location): JsonResponse
    {
        $this->allow($request, 'locations.manage');
        $this->sameCompany($request, $location);
        $location->delete();

        return $this->message('Location deleted.');
    }

    private function save(int|string $companyId, ?Location $location, array $data): Location
    {
        return DB::transaction(function () use ($companyId, $location, $data) {
            $location ??= new Location(['company_id' => $companyId]);
            $location->fill($data);
            $location->company_id = $companyId;
            $location->save();

            if (! empty($data['is_default'])) {
                Location::forCompany($companyId)->whereKeyNot($location->id)->update(['is_default' => false]);
            }

            return $location->refresh();
        });
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    private function sameCompany(Request $request, Location $location): void
    {
        abort_unless((string) $location->company_id === (string) $this->company($request)->id, 404);
    }
}
