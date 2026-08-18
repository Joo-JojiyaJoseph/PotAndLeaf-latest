<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Services\ReportExportService;
use App\Services\ReportService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportExportService $export,
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
        $company = $this->company($request);

        $companies = $user->is_super_admin
            ? Company::active()->orderBy('name')->get(['id', 'name', 'code'])
            : collect([$company->only(['id', 'name', 'code'])]);

        $locations = Location::forCompany($company->id)->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('name')
            ->get(['id', 'name', 'code']);

        $customers = Customer::forCompany($company->id)->where('status', 'active')
            ->orderBy('name')->get(['id', 'name']);

        return $this->ok([
            'companies' => $companies,
            'locations' => $locations,
            'customers' => $customers,
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

    private function reportCompanyId(Request $request): int|string
    {
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
}
