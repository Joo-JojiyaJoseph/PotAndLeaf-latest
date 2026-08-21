<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Summary figures for the dashboard cards, scoped to the selected company.
 * Real transactional metrics (sales, transfers, commission) arrive with their
 * modules; for now this returns what the master data can answer honestly.
 */
class DashboardController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view', $this->company($request)->id), 403);

        $companyId = $this->listCompanyId($request);

        $lowStock = Product::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->whereColumn('current_stock', '<=', 'reorder_level')->count();

        $company = $this->listCompany($request);

        return $this->ok([
            'company' => ['id' => $company->id, 'name' => $company->name],
            'cards'   => [
                ['key' => 'suppliers',  'label' => 'Suppliers',    'value' => Supplier::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))->count()],
                ['key' => 'products',   'label' => 'Products',     'value' => Product::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))->count()],
                ['key' => 'low_stock',  'label' => 'Low stock',    'value' => $lowStock, 'tone' => $lowStock > 0 ? 'warning' : 'default'],
                ['key' => 'members',    'label' => 'Users',        'value' => $companyId !== null ? $company->users()->count() : \App\Models\User::count()],
            ],
            // Placeholder feed until transactional modules write real events.
            'activity' => [],
        ]);
    }
}
