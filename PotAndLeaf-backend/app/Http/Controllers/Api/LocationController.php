<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\StoreLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use App\Services\LocationStockService;
use App\Support\Api\ApiResponse;
use App\Support\Api\AssertsRecordCompany;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LocationController extends Controller
{
    use ApiResponse, AssertsRecordCompany, ResolvesFilterCompany;

    public function __construct(private readonly LocationStockService $stock) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->listCompanyId($request);
        $this->allow($request, 'locations.view');

        return $this->ok(LocationResource::collection(
            Location::query()
                ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
        ));
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validated();
        // A company's first location is its main one.
        $wantsMain = ! empty($data['is_default']);
        if (Location::forCompany($company->id)->doesntExist()) {
            $data['is_default'] = true;
            $wantsMain = false;
        } else {
            // Create it first, then make it the main location through the safe switch.
            $data['is_default'] = false;
        }
        $location = $this->save($company->id, null, $data);
        if ($wantsMain) {
            $this->stock->switchMain($company->id, $location->id);
            Location::forCompany($company->id)->whereKeyNot($location->id)->update(['is_default' => false]);
            $location->update(['is_default' => true]);
            $location->refresh();
        }

        return $this->created(new LocationResource($location), 'Location created.');
    }

    public function update(StoreLocationRequest $request, Location $location): JsonResponse
    {
        $this->assertRecordCompany($request, $location, writable: true);
        $data = $request->validated();
        // The main location cannot be switched off or un-marked; make another location the main one instead.
        if ($location->is_default) {
            $data['is_default'] = true;
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => 'The main location cannot be switched off. Make another location the main one first.']);
            }
        } elseif (array_key_exists('is_active', $data) && ! $data['is_active'] && $location->is_active && $this->stock->holdsStock($location->company_id, $location->id)) {
            throw ValidationException::withMessages(['is_active' => 'This location still holds stock. Transfer it out before switching the location off.']);
        }
        $updated = $this->save($this->company($request)->id, $location, $data);

        return $this->ok(new LocationResource($updated), 'Location updated.');
    }

    public function destroy(Request $request, Location $location): JsonResponse
    {
        $this->allow($request, 'locations.manage');
        $this->assertRecordCompany($request, $location, writable: true);
        abort_if(Location::forCompany($location->company_id)->whereKeyNot($location->id)->doesntExist(), 422, 'This is the company\'s only location and cannot be deleted.');
        abort_if($location->is_default, 422, 'The main location cannot be deleted. Make another location the main one first.');
        abort_if($this->stock->holdsStock($location->company_id, $location->id), 422, 'This location still holds stock. Transfer it out first.');
        abort_if($this->isUsed($location), 422, 'This location has bills, purchases or transfers recorded against it, so it cannot be deleted. Switch it off instead.');

        DB::transaction(function () use ($location) {
            if (Schema::hasColumn('company_user', 'location_id')) {
                DB::table('company_user')->where('location_id', $location->id)->update(['location_id' => null]);
            }
            \App\Models\LocationStock::where('location_id', $location->id)->delete();
            $location->delete();
        });

        return $this->message('Location deleted.');
    }

    private function save(int|string $companyId, ?Location $location, array $data): Location
    {
        return DB::transaction(function () use ($companyId, $location, $data) {
            $location ??= new Location(['company_id' => $companyId]);
            // Only a branch can be a landscape division; a godown just stores stock.
            if (($data['type'] ?? $location->type) === 'godown') {
                $data['is_landscape'] = false;
            }
            $location->fill($data);
            $location->company_id = $companyId;
            $becomesMain = ! empty($data['is_default']) && $location->exists && ! $location->getOriginal('is_default');
            if ($becomesMain) {
                // Move the "remainder" holder first so no location's quantity changes.
                $this->stock->switchMain($companyId, $location->id);
            }
            $location->save();

            if (! empty($data['is_default'])) {
                Location::forCompany($companyId)->whereKeyNot($location->id)->update(['is_default' => false]);
            }

            return $location->refresh();
        });
    }

    /** True when any document points at the location. */
    private function isUsed(Location $location): bool
    {
        foreach (['sales', 'purchases', 'sales_returns', 'damage_entries', 'backorders'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'location_id')
                && DB::table($table)->where('location_id', $location->id)->exists()) {
                return true;
            }
        }

        return DB::table('stock_transfers')->where(fn ($q) => $q->where('from_location_id', $location->id)->orWhere('to_location_id', $location->id))->exists();
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

}
