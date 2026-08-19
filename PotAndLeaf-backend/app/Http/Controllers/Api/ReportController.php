<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Supplier;
use App\Services\ReportExportService;
use App\Services\ReportService;
use App\Services\SalesAnalyticsService;
use App\Services\EodManagementSummaryService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportExportService $export,
        private readonly SalesAnalyticsService $analytics,
        private readonly EodManagementSummaryService $eodManagement,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $this->allow($request, 'reports.view');
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        return $this->ok($this->reports->dashboard($companyId, $from, $to, null));
    }

    public function formData(Request $request): JsonResponse
    {
        $this->allow($request, 'reports.view');
        $user = $request->user();
        $headerCompany = $this->company($request);
        $scopeId = $this->reportCompanyId($request);

        $companies = $user->is_super_admin
            ? Company::active()->orderBy('name')->get(['id', 'name', 'code'])
            : collect([$headerCompany->only(['id', 'name', 'code'])]);

        $filterCompanyId = $scopeId ?? $headerCompany->id;

        $locations = $scopeId === null
            ? collect()
            : Location::forCompany($filterCompanyId)->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('name')
                ->get(['id', 'name', 'code']);

        $customers = $scopeId === null
            ? collect()
            : Customer::forCompany($filterCompanyId)->where('status', 'active')
                ->orderBy('name')->get(['id', 'name']);

        $suppliers = $scopeId === null
            ? collect()
            : Supplier::forCompany($filterCompanyId)->where('status', 'active')
                ->orderBy('name')->get(['id', 'name']);

        return $this->ok([
            'companies' => $companies,
            'locations' => $locations,
            'customers' => $customers,
            'suppliers' => $suppliers,
            'scoped_company_id' => $scopeId,
        ]);
    }

    public function margin(Request $request): JsonResponse
    {
        $this->allowHo($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        return $this->ok($this->reports->marginAnalysis($companyId, $from, $to, 'product'));
    }

    public function profit(Request $request): JsonResponse
    {
        $this->allowHo($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();
        $period = $request->query('period', 'daily');
        if (! in_array($period, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
            $period = 'daily';
        }

        return $this->ok($this->reports->approximateProfit($companyId, $from, $to, $period, null));
    }

    public function priceLevels(Request $request): JsonResponse
    {
        $this->allow($request, 'reports.view');
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        return $this->ok($this->reports->salesByPriceLevel($companyId, $from, $to));
    }

    public function exportDashboard(Request $request)
    {
        $this->allow($request, 'reports.view');
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();
        $format = $request->query('format', 'pdf');

        $data = $this->reports->dashboard($companyId, $from, $to, null);
        $rows = collect($data['top_products'])->map(fn ($r) => [
            'name' => $r['name'], 'qty' => $r['qty'], 'revenue' => $r['revenue'],
        ]);
        $headers = ['name', 'qty', 'revenue'];
        $labels = ['name' => 'Product', 'qty' => 'Qty', 'revenue' => 'Revenue'];
        $meta = ['From' => $from, 'To' => $to, 'Sales total' => $data['sales']['total']];

        if ($format === 'excel') {
            return $this->export->excelCsv("dashboard-{$from}-{$to}.csv", $rows, $headers, $labels);
        }

        return $this->export->pdf('Dashboard — Top products', $rows, $headers, $labels, $meta)
            ->download("dashboard-{$from}-{$to}.pdf");
    }

    public function exportMargin(Request $request)
    {
        $this->allowHo($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();
        $format = $request->query('format', 'pdf');

        $data = $this->reports->marginAnalysis($companyId, $from, $to, 'product');
        $headers = ['name', 'revenue', 'cogs', 'margin', 'margin_pct'];
        $labels = [
            'name' => 'Product', 'revenue' => 'Revenue', 'cogs' => 'COGS', 'margin' => 'Margin', 'margin_pct' => 'Margin %',
        ];

        if ($format === 'excel') {
            return $this->export->excelCsv("margin-{$from}-{$to}.csv", $data['rows'], $headers, $labels);
        }

        return $this->export->pdf('Profit & Margin', $data['rows'], $headers, $labels, [
            'From' => $from, 'To' => $to,
        ])->download("margin-{$from}-{$to}.pdf");
    }

    public function exportProfit(Request $request)
    {
        $this->allowHo($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();
        $period = $request->query('period', 'daily');
        $format = $request->query('format', 'pdf');

        $data = $this->reports->approximateProfit($companyId, $from, $to, $period, null);
        $headers = ['location_name', 'sales', 'cogs', 'expenses', 'profit'];
        $labels = [
            'location_name' => 'Company', 'sales' => 'Sales', 'cogs' => 'COGS',
            'expenses' => 'Expenses', 'profit' => 'Profit',
        ];

        if ($format === 'excel') {
            return $this->export->excelCsv("profit-{$from}-{$to}.csv", $data['by_branch'], $headers, $labels);
        }

        return $this->export->pdf('Approximate Profit', $data['by_branch'], $headers, $labels, [
            'From' => $from, 'To' => $to,
            'Aggregate profit' => $data['aggregate']['profit'],
        ])->download("profit-{$from}-{$to}.pdf");
    }

    public function rentalDelivery(Request $request): JsonResponse
    {
        $this->allowRentalReports($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        return $this->ok($this->reports->rentalDelivery(
            $companyId, $from, $to,
            $request->query('location_id'),
            (int) $request->query('per_page', 50),
            (int) $request->query('page', 1),
        ));
    }

    public function rentalIncome(Request $request): JsonResponse
    {
        $this->allowRentalReports($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();
        $period = $request->query('period', 'daily');

        return $this->ok($this->reports->rentalIncome(
            $companyId, $from, $to, $period, $request->query('location_id'),
        ));
    }

    public function rentalCurrent(Request $request): JsonResponse
    {
        $this->allowRentalReports($request);
        $companyId = $this->reportCompanyId($request);

        return $this->ok($this->reports->rentalCurrent(
            $companyId,
            $request->query('location_id'),
            (int) $request->query('per_page', 50),
            (int) $request->query('page', 1),
        ));
    }

    public function rentalCustomer(Request $request, string $customer): JsonResponse
    {
        $this->allowRentalReports($request);
        $companyId = $this->reportCompanyId($request);

        abort_unless(
            Customer::forCompany($companyId)->whereKey($customer)->exists(),
            404,
        );

        return $this->ok($this->reports->rentalCustomerHistory(
            $companyId,
            $customer,
            $request->query('location_id'),
            $request->query('from'),
            $request->query('to'),
            (int) $request->query('per_page', 50),
            (int) $request->query('page', 1),
        ));
    }

    public function exportRentalDelivery(Request $request)
    {
        $this->allowRentalReports($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();
        $format = $request->query('format', 'pdf');

        $data = $this->reports->rentalDelivery(
            $companyId, $from, $to, $request->query('location_id'), 10000, 1,
        );
        $rows = collect($data->items());
        $headers = ['rental_no', 'customer_name', 'items_summary', 'delivery_date', 'deposit', 'status'];
        $labels = [
            'rental_no' => 'Rental', 'customer_name' => 'Customer', 'items_summary' => 'Items',
            'delivery_date' => 'Delivery date', 'deposit' => 'Deposit', 'status' => 'Status',
        ];

        if ($format === 'excel') {
            return $this->export->excelCsv("rental-delivery-{$from}-{$to}.csv", $rows, $headers, $labels);
        }

        return $this->export->pdf('Rental Delivery Report', $rows, $headers, $labels, [
            'From' => $from, 'To' => $to,
        ])->download("rental-delivery-{$from}-{$to}.pdf");
    }

    public function exportRentalIncome(Request $request)
    {
        $this->allowRentalReports($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();
        $period = $request->query('period', 'daily');
        $format = $request->query('format', 'pdf');

        $data = $this->reports->rentalIncome($companyId, $from, $to, $period, $request->query('location_id'));
        $headers = ['invoice_no', 'rental_no', 'customer_name', 'location_name', 'period_from', 'period_to', 'amount', 'status'];
        $labels = [
            'invoice_no' => 'Invoice', 'rental_no' => 'Rental', 'customer_name' => 'Customer',
            'location_name' => 'Branch', 'period_from' => 'From', 'period_to' => 'To',
            'amount' => 'Amount', 'status' => 'Status',
        ];

        if ($format === 'excel') {
            return $this->export->excelCsv("rental-income-{$from}-{$to}.csv", $data['rows'], $headers, $labels);
        }

        return $this->export->pdf('Rental Income Report', $data['rows'], $headers, $labels, [
            'From' => $from, 'To' => $to, 'Total' => $data['total'],
        ])->download("rental-income-{$from}-{$to}.pdf");
    }

    public function exportRentalCurrent(Request $request)
    {
        $this->allowRentalReports($request);
        $companyId = $this->reportCompanyId($request);
        $format = $request->query('format', 'pdf');

        $data = $this->reports->rentalCurrent($companyId, $request->query('location_id'), 10000, 1);
        $rows = collect($data->items());
        $headers = ['rental_no', 'customer_name', 'product_name', 'qty', 'delivery_date', 'days_elapsed', 'status'];
        $labels = [
            'rental_no' => 'Rental', 'customer_name' => 'Customer', 'product_name' => 'Item',
            'qty' => 'Qty out', 'delivery_date' => 'Delivery', 'days_elapsed' => 'Days elapsed', 'status' => 'Status',
        ];

        if ($format === 'excel') {
            return $this->export->excelCsv('rental-current.csv', $rows, $headers, $labels);
        }

        return $this->export->pdf('Currently Rented Report', $rows, $headers, $labels, [
            'As of' => now()->toDateString(),
        ])->download('rental-current.pdf');
    }

    public function exportRentalCustomer(Request $request, string $customer)
    {
        $this->allowRentalReports($request);
        $companyId = $this->reportCompanyId($request);
        $format = $request->query('format', 'pdf');

        abort_unless(
            Customer::forCompany($companyId)->whereKey($customer)->exists(),
            404,
        );

        $data = $this->reports->rentalCustomerHistory(
            $companyId, $customer, $request->query('location_id'),
            $request->query('from'), $request->query('to'), 10000, 1,
        );
        $rows = collect($data->items());
        $headers = [
            'rental_no', 'start_date', 'delivery_date', 'return_date', 'items_summary',
            'deposit', 'rental_charge', 'damage_charge', 'missing_charge', 'refund_amount', 'status',
        ];
        $labels = [
            'rental_no' => 'Rental', 'start_date' => 'Start', 'delivery_date' => 'Delivery',
            'return_date' => 'Return', 'items_summary' => 'Items', 'deposit' => 'Deposit',
            'rental_charge' => 'Rental charge', 'damage_charge' => 'Damage',
            'missing_charge' => 'Missing', 'refund_amount' => 'Refund', 'status' => 'Status',
        ];

        if ($format === 'excel') {
            return $this->export->excelCsv("rental-customer-{$customer}.csv", $rows, $headers, $labels);
        }

        return $this->export->pdf('Customer Rental History', $rows, $headers, $labels)->download("rental-customer-{$customer}.pdf");
    }

    public function productionSummary(Request $request): JsonResponse
    {
        $this->allowProductionReports($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        $result = $this->reports->productionSummary(
            $companyId, $from, $to,
            (int) $request->query('per_page', 50),
            (int) $request->query('page', 1),
        );
        $p = $result['orders'];

        return $this->ok([
            'summary' => $result['summary'],
            'data'    => $p->items(),
            'meta'    => [
                'current_page' => $p->currentPage(),
                'last_page'    => $p->lastPage(),
                'per_page'     => $p->perPage(),
                'total'        => $p->total(),
            ],
        ]);
    }

    public function productionByProduct(Request $request): JsonResponse
    {
        $this->allowProductionReports($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        return $this->ok($this->reports->productionByProduct($companyId, $from, $to));
    }

    public function productionBySupervisor(Request $request): JsonResponse
    {
        $this->allowProductionReports($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        return $this->ok($this->reports->productionBySupervisor($companyId, $from, $to));
    }

    public function productionBatches(Request $request): JsonResponse
    {
        $this->allowProductionReports($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        return $this->ok($this->reports->productionBatches(
            $companyId, $from, $to,
            (int) $request->query('per_page', 50),
            (int) $request->query('page', 1),
        ));
    }

    public function transferSummary(Request $request): JsonResponse
    {
        $this->allowTransferReports($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        $result = $this->reports->transferSummary(
            $companyId, $from, $to,
            (int) $request->query('per_page', 50),
            (int) $request->query('page', 1),
        );
        $p = $result['orders'];

        return $this->ok([
            'summary' => $result['summary'],
            'data'    => $p->items(),
            'meta'    => [
                'current_page' => $p->currentPage(),
                'last_page'    => $p->lastPage(),
                'per_page'     => $p->perPage(),
                'total'        => $p->total(),
            ],
        ]);
    }

    public function transferInTransit(Request $request): JsonResponse
    {
        $this->allowTransferReports($request);
        $companyId = $this->reportCompanyId($request);

        $p = $this->reports->transferInTransit(
            $companyId,
            (int) $request->query('per_page', 50),
            (int) $request->query('page', 1),
        );

        return $this->ok($p);
    }

    public function cashBook(Request $request): JsonResponse
    {
        $this->allowAccounting($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        return $this->ok($this->reports->cashBook(
            $companyId, $from, $to,
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 50),
        ));
    }

    public function bankBook(Request $request): JsonResponse
    {
        $this->allowAccounting($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->subDays(29)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        return $this->ok($this->reports->bankBook(
            $companyId, $from, $to,
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 50),
        ));
    }

    public function debtorLedger(Request $request): JsonResponse
    {
        $this->allowAccounting($request);
        $companyId = $this->reportCompanyId($request);
        $request->validate(['customer_id' => ['required', 'uuid']]);
        $from = $request->query('from') ?: now()->subDays(89)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        return $this->ok($this->reports->debtorLedger($companyId, $request->query('customer_id'), $from, $to));
    }

    public function creditorLedger(Request $request): JsonResponse
    {
        $this->allowAccounting($request);
        $companyId = $this->reportCompanyId($request);
        $request->validate(['supplier_id' => ['required', 'uuid']]);
        $from = $request->query('from') ?: now()->subDays(89)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        return $this->ok($this->reports->creditorLedger($companyId, $request->query('supplier_id'), $from, $to));
    }

    public function ageingReceivables(Request $request): JsonResponse
    {
        $this->allowAccounting($request);

        return $this->ok($this->reports->ageingReceivables($this->reportCompanyId($request)));
    }

    public function ageingPayables(Request $request): JsonResponse
    {
        $this->allowAccounting($request);

        return $this->ok($this->reports->ageingPayables($this->reportCompanyId($request)));
    }

    public function salesComparisonMonth(Request $request): JsonResponse
    {
        $this->allow($request, 'reports.view');
        $companyId = $this->reportCompanyId($request);

        return $this->ok($this->analytics->monthComparison(
            $companyId,
            $request->query('as_of'),
            $request->query('location_id'),
        ));
    }

    public function salesComparisonYoy(Request $request): JsonResponse
    {
        $this->allow($request, 'reports.view');
        $companyId = $this->reportCompanyId($request);

        return $this->ok($this->analytics->yearOnYear(
            $companyId,
            $request->query('month'),
            $request->query('location_id'),
        ));
    }

    public function salesYtd(Request $request): JsonResponse
    {
        $this->allow($request, 'reports.view');
        $companyId = $this->reportCompanyId($request);

        return $this->ok($this->analytics->yearToDate(
            $companyId,
            $request->query('as_of'),
            $request->query('location_id'),
        ));
    }

    public function twelveMonthTrend(Request $request): JsonResponse
    {
        $this->allow($request, 'reports.view');
        $companyId = $this->reportCompanyId($request);

        return $this->ok($this->analytics->twelveMonthTrend(
            $companyId,
            $request->query('as_of'),
            $request->query('location_id'),
        ));
    }

    public function gstReconciliation(Request $request): JsonResponse
    {
        $this->allowAccounting($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        return $this->ok($this->analytics->gstReconciliation($companyId, $from, $to));
    }

    public function commissionReport(Request $request): JsonResponse
    {
        $this->allowCommissionReport($request);
        $companyId = $this->reportCompanyId($request);
        $from = $request->query('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        return $this->ok($this->analytics->commissionReport(
            $companyId,
            $from,
            $to,
            $request->query('user_id') ? (int) $request->query('user_id') : null,
        ));
    }

    public function leaderboard(Request $request): JsonResponse
    {
        $this->allow($request, 'reports.view');
        $companyId = $this->reportCompanyId($request);
        $period = $request->query('period', 'month');
        if (! in_array($period, ['month', 'year'], true)) {
            $period = 'month';
        }

        return $this->ok($this->analytics->leaderboard(
            $companyId,
            $period,
            $request->query('as_of'),
            $request->query('location_id'),
        ));
    }

    public function eodManagementPreview(Request $request): JsonResponse
    {
        $this->allowEodManagement($request);
        $companyId = $this->company($request)->id;
        $date = $request->query('date') ?: now()->toDateString();

        return $this->ok($this->eodManagement->build($companyId, $date));
    }

    public function sendEodManagement(Request $request): JsonResponse
    {
        $this->allowEodManagement($request);
        $companyId = $this->company($request)->id;
        $date = $request->input('date') ?: now()->toDateString();

        return $this->ok(
            $this->eodManagement->send($companyId, $date, (bool) $request->boolean('force')),
            'Management EOD summary processed.',
        );
    }

    private function reportCompanyId(Request $request): int|string|null
    {
        if ($request->user()->is_super_admin && $request->query('company_id') === 'all') {
            return null;
        }

        if ($request->user()->is_super_admin && $request->filled('company_id')) {
            return (int) $request->query('company_id');
        }

        return $this->company($request)->id;
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    private function allowHo(Request $request): void
    {
        $company = $this->company($request);
        $user = $request->user();
        $ok = $user->is_super_admin
            || $user->hasPermission('*', $company->id)
            || $user->hasPermission('reports.margin', $company->id)
            || $user->hasPermission('reports.profit', $company->id)
            || $user->hasPermission('products.view_cost', $company->id);
        abort_unless($ok, 403);
    }

    /** Rental reports require reports.view (reports.*) and rental.view (rental.*). */
    private function allowRentalReports(Request $request): void
    {
        $companyId = $this->company($request)->id;
        $user = $request->user();
        abort_unless(
            $user->hasPermission('reports.view', $companyId)
            && $user->hasPermission('rental.view', $companyId),
            403,
        );
    }

    /** Production reports require reports.view and production.view. */
    private function allowProductionReports(Request $request): void
    {
        $companyId = $this->company($request)->id;
        $user = $request->user();
        abort_unless(
            $user->hasPermission('reports.view', $companyId)
            && $user->hasPermission('production.view', $companyId),
            403,
        );
    }

    /** Transfer reports require reports.view and transfers.view. */
    private function allowTransferReports(Request $request): void
    {
        $companyId = $this->company($request)->id;
        $user = $request->user();
        abort_unless(
            $user->hasPermission('reports.view', $companyId)
            && $user->hasPermission('transfers.view', $companyId),
            403,
        );
    }

    /** Accounting reports require reports.view plus receipts/payments visibility. */
    private function allowAccounting(Request $request): void
    {
        $companyId = $this->company($request)->id;
        $user = $request->user();
        abort_unless(
            $user->hasPermission('reports.view', $companyId)
            && ($user->hasPermission('receipts.view', $companyId) || $user->hasPermission('payments.view', $companyId)),
            403,
        );
    }

    private function allowCommissionReport(Request $request): void
    {
        $companyId = $this->company($request)->id;
        $user = $request->user();
        abort_unless(
            $user->is_super_admin
            || $user->hasPermission('*', $companyId)
            || ($user->hasPermission('reports.view', $companyId) && $user->hasPermission('commission.view', $companyId)),
            403,
        );
    }

    private function allowEodManagement(Request $request): void
    {
        $companyId = $this->company($request)->id;
        $user = $request->user();
        abort_unless(
            $user->is_super_admin
            || $user->hasPermission('*', $companyId)
            || $user->hasPermission('settings.update', $companyId)
            || $user->hasPermission('commission.manage', $companyId),
            403,
        );
    }
}
