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
        $company = $this->listCompany($request);
        $companyId = $company->id;

        $lowStock = Product::query()->forCompany($companyId)
            ->whereColumn('current_stock', '<=', 'reorder_level')->count();

        return $this->ok([
            'company' => ['id' => $company->id, 'name' => $company->name],
            'cards'   => [
                ['key' => 'suppliers',  'label' => 'Suppliers',    'value' => Supplier::query()->forCompany($companyId)->count()],
                ['key' => 'products',   'label' => 'Products',     'value' => Product::query()->forCompany($companyId)->count()],
                ['key' => 'low_stock',  'label' => 'Low stock',    'value' => $lowStock, 'tone' => $lowStock > 0 ? 'warning' : 'default'],
                ['key' => 'members',    'label' => 'Users',        'value' => $company->users()->count()],
            ],
            // Placeholder feed until transactional modules write real events.
            'activity' => [],
        ]);
    }
}
