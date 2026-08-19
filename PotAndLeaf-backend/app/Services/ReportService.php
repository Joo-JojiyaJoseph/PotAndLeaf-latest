<?php

namespace App\Services;

use App\Models\CommissionPayout;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductionOrder;
use App\Models\Purchase;
use App\Models\Rental;
use App\Models\RentalInvoice;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SettingsService $settings,
        private readonly ReceiptService $receipts,
        private readonly PaymentService $payments,
    ) {}

    public function dashboard(int|string|null $companyId, string $from, string $to, ?string $locationId = null): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        return [
            'range'         => ['from' => $from, 'to' => $to],
            'location_id'   => $locationId,
            'sales'         => $this->sales($companyId, $from, $to, $locationId),
            'purchases'     => $this->purchases($companyId, $from, $to),
            'inventory'     => $this->inventorySnapshot($companyId),
            'receivables'   => round((float) Customer::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))->sum('outstanding'), 2),
            'payables'      => round((float) Supplier::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))->sum('outstanding'), 2),
            'top_products'  => $this->topProducts($companyId, $from, $to, $locationId),
            'top_customers' => $this->topCustomers($companyId, $from, $to, $locationId),
            'production'    => $this->production($companyId, $from, $to),
            'rentals'       => $this->rentals($companyId, $from, $to),
            'transfers'     => $this->transfers($companyId, $from, $to),
        ];
    }

    /** Product-wise or shop-wise margin (HO cost data). */
    public function marginAnalysis(int|string $companyId, string $from, string $to, string $groupBy = 'product'): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        if ($groupBy === 'shop') {
            $rows = SaleItem::query()
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
                ->leftJoin('locations', 'locations.id', '=', 'sales.location_id')
                ->where('sales.company_id', $companyId)
                ->where('sales.status', 'confirmed')
                ->whereBetween('sales.sale_date', [$from, $to])
                ->selectRaw('sales.location_id as group_id, COALESCE(locations.name, ?) as group_name,
                    SUM(sale_items.line_total) as revenue,
                    SUM(sale_items.qty * COALESCE(products.cost_price, 0)) as cogs', ['Unassigned'])
                ->groupBy('sales.location_id', 'locations.name')
                ->get();
        } else {
            $rows = SaleItem::query()
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
                ->where('sales.company_id', $companyId)
                ->where('sales.status', 'confirmed')
                ->whereBetween('sales.sale_date', [$from, $to])
                ->selectRaw('sale_items.product_id as group_id, COALESCE(sale_items.product_name, products.name, ?) as group_name,
                    SUM(sale_items.line_total) as revenue,
                    SUM(sale_items.qty * COALESCE(products.cost_price, 0)) as cogs', ['Unknown'])
                ->groupBy('sale_items.product_id', 'sale_items.product_name', 'products.name')
                ->get();
        }

        $mapped = $rows->map(function ($r) {
            $revenue = (float) $r->revenue;
            $cogs = (float) $r->cogs;
            $margin = round($revenue - $cogs, 2);
            $pct = $revenue > 0 ? round(($margin / $revenue) * 100, 2) : 0.0;

            return [
                'id'         => $r->group_id,
                'name'       => $r->group_name,
                'revenue'    => round($revenue, 2),
                'cogs'       => round($cogs, 2),
                'margin'     => $margin,
                'margin_pct' => $pct,
            ];
        })->sortByDesc('margin_pct')->values()->all();

        return [
            'group_by' => $groupBy,
            'from'     => $from,
            'to'       => $to,
            'rows'     => $mapped,
        ];
    }

    /**
     * Approximate profit: Sales − COGS − daily expenses.
     */
    public function approximateProfit(
        int|string $companyId,
        string $from,
        string $to,
        string $period = 'daily',
        ?string $branchId = null,
    ): array {
        $fromC = Carbon::parse($from)->startOfDay();
        $toC = Carbon::parse($to)->endOfDay();
        $dailyExpense = $this->settings->getFloat($companyId, 'daily_expense');

        $salesQ = Sale::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->where('status', 'confirmed')
            ->whereBetween('sale_date', [$fromC->toDateString(), $toC->toDateString()])
            ->when($branchId, fn ($q) => $q->where('location_id', $branchId));

        $salesTotal = (float) (clone $salesQ)->sum('grand_total');

        $cogs = (float) SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.company_id', $companyId)
            ->where('sales.status', 'confirmed')
            ->whereBetween('sales.sale_date', [$fromC->toDateString(), $toC->toDateString()])
            ->when($branchId, fn ($q) => $q->where('sales.location_id', $branchId))
            ->sum(DB::raw('sale_items.qty * COALESCE(products.cost_price, 0)'));

        $days = max(1, (int) $fromC->diffInDays($toC) + 1);
        $expenses = round($dailyExpense * $days, 2);
        $profit = round($salesTotal - $cogs - $expenses, 2);

        $bucketExpr = match ($period) {
            'weekly'  => 'YEARWEEK(sale_date, 1)',
            'monthly' => "DATE_FORMAT(sale_date, '%Y-%m')",
            'yearly'  => 'YEAR(sale_date)',
            default   => 'DATE(sale_date)',
        };

        $trend = Sale::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->where('status', 'confirmed')
            ->whereBetween('sale_date', [$fromC->toDateString(), $toC->toDateString()])
            ->when($branchId, fn ($q) => $q->where('location_id', $branchId))
            ->selectRaw("{$bucketExpr} as bucket, SUM(grand_total) as sales")
            ->groupBy(DB::raw($bucketExpr))
            ->orderBy('bucket')
            ->get()
            ->map(fn ($r) => [
                'period' => (string) $r->bucket,
                'sales'  => round((float) $r->sales, 2),
            ])
            ->all();

        $byBranch = Location::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->orderBy('name')
            ->get()
            ->map(function (Location $loc) use ($companyId, $fromC, $toC, $dailyExpense, $days) {
                $sales = (float) Sale::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
                    ->where('status', 'confirmed')
                    ->where('location_id', $loc->id)
                    ->whereBetween('sale_date', [$fromC->toDateString(), $toC->toDateString()])
                    ->sum('grand_total');
                $branchCogs = (float) SaleItem::query()
                    ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                    ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
                    ->where('sales.company_id', $companyId)
                    ->where('sales.status', 'confirmed')
                    ->where('sales.location_id', $loc->id)
                    ->whereBetween('sales.sale_date', [$fromC->toDateString(), $toC->toDateString()])
                    ->sum(DB::raw('sale_items.qty * COALESCE(products.cost_price, 0)'));
                $exp = round($dailyExpense * $days, 2);

                return [
                    'location_id'   => $loc->id,
                    'location_name' => $loc->name,
                    'sales'         => round($sales, 2),
                    'cogs'          => round($branchCogs, 2),
                    'expenses'      => $exp,
                    'profit'        => round($sales - $branchCogs - $exp, 2),
                ];
            })
            ->filter(fn ($r) => $r['sales'] > 0 || $r['cogs'] > 0)
            ->values()
            ->all();

        return [
            'from'      => $fromC->toDateString(),
            'to'        => $toC->toDateString(),
            'period'    => $period,
            'branch_id' => $branchId,
            'aggregate' => [
                'sales'    => round($salesTotal, 2),
                'cogs'     => round($cogs, 2),
                'expenses' => $expenses,
                'profit'   => $profit,
                'days'     => $days,
            ],
            'trend'     => $trend,
            'by_branch' => $byBranch,
        ];
    }

    private function sales(int|string $companyId, string $from, string $to, ?string $locationId = null): array
    {
        $base = Sale::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))->where('status', 'confirmed')->whereBetween('sale_date', [$from, $to])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId));

        $byMode = (clone $base)->selectRaw('payment_mode, SUM(grand_total) as total')
            ->groupBy('payment_mode')->pluck('total', 'payment_mode')
            ->map(fn ($v) => round((float) $v, 2));

        $trend = (clone $base)->selectRaw('sale_date as d, SUM(grand_total) as total')
            ->groupBy('sale_date')->orderBy('sale_date')->get()
            ->map(fn ($r) => ['date' => Carbon::parse($r->d)->toDateString(), 'total' => round((float) $r->total, 2)]);

        return [
            'total'   => round((float) (clone $base)->sum('grand_total'), 2),
            'count'   => (clone $base)->count(),
            'by_mode' => $byMode,
            'trend'   => $trend,
        ];
    }

    private function purchases(int|string $companyId, string $from, string $to): array
    {
        $base = Purchase::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))->where('status', 'confirmed')->whereBetween('purchase_date', [$from, $to]);

        return ['total' => round((float) (clone $base)->sum('grand_total'), 2), 'count' => (clone $base)->count()];
    }

    private function inventorySnapshot(int|string $companyId): array
    {
        $valuation = $this->inventory->valuation($companyId);
        $lowStock = Product::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->where('reorder_level', '>', 0)->count();

        return [
            'stock_value' => round((float) ($valuation['totals']['total_value'] ?? $valuation['total'] ?? 0), 2),
            'low_stock'   => $lowStock,
            'skus'        => Product::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))->count(),
        ];
    }

    private function topProducts(int|string $companyId, string $from, string $to, ?string $locationId = null): array
    {
        return SaleItem::query()
            ->whereHas('sale', fn ($q) => $q->when($companyId !== null, fn ($sq) => $sq->forCompany($companyId))->where('status', 'confirmed')
                ->whereBetween('sale_date', [$from, $to])
                ->when($locationId, fn ($qq) => $qq->where('location_id', $locationId)))
            ->selectRaw('product_name, SUM(qty) as qty, SUM(line_total) as revenue')
            ->groupBy('product_name')->orderByDesc('revenue')->limit(5)->get()
            ->map(fn ($r) => ['name' => $r->product_name, 'qty' => round((float) $r->qty, 2), 'revenue' => round((float) $r->revenue, 2)])
            ->all();
    }

    private function topCustomers(int|string $companyId, string $from, string $to, ?string $locationId = null): array
    {
        return Sale::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))->where('status', 'confirmed')->whereBetween('sale_date', [$from, $to])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->selectRaw('customer_name, SUM(grand_total) as revenue')
            ->groupBy('customer_name')->orderByDesc('revenue')->limit(5)->get()
            ->map(fn ($r) => ['name' => $r->customer_name, 'revenue' => round((float) $r->revenue, 2)])
            ->all();
    }

    private function production(int|string $companyId, string $from, string $to): array
    {
        $base = ProductionOrder::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->where('status', 'completed');
        $base = $this->applyDateRange($base, 'order_date', $from, $to);

        return [
            'completed'    => (clone $base)->count(),
            'output_value' => round((float) (clone $base)->sum('total_input_cost'), 2),
        ];
    }

    private function rentals(int|string|null $companyId, string $from, string $to): array
    {
        $today = Carbon::today()->toDateString();

        return [
            'active'           => Rental::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))->where('status', 'active')->count(),
            'overdue_returns'  => Rental::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
                ->where('status', 'active')
                ->whereNotNull('expected_end_date')
                ->whereDate('expected_end_date', '<', $today)
                ->count(),
            'payment_overdue'  => RentalInvoice::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
                ->where('status', 'unpaid')
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $today)
                ->count(),
            'invoiced'         => round((float) RentalInvoice::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
                ->whereBetween('period_from', [$from, $to])->sum('amount'), 2),
        ];
    }

    private function transfers(int|string|null $companyId, string $from, string $to): array
    {
        $base = StockTransfer::query()
            ->when($companyId !== null, fn ($q) => $q->where(fn ($inner) => $inner->where('company_id', $companyId)->orWhere('to_company_id', $companyId)));

        return [
            'in_transit'        => (clone $base)->where('status', 'in_transit')->count(),
            'received_in_range' => $this->applyDateRange((clone $base)->where('status', 'received'), 'transfer_date', $from, $to)->count(),
            'pending_approval'  => (clone $base)->where('status', 'requested')->count(),
        ];
    }

    /**
     * Rental deliveries in a date range (activation = delivery).
     * Status is derived: active / returned / overdue (active past expected end).
     */
    public function rentalDelivery(
        int|string $companyId,
        string $from,
        string $to,
        ?string $locationId = null,
        int $perPage = 50,
        int $page = 1,
    ): LengthAwarePaginator {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->endOfDay();
        $today = Carbon::today()->toDateString();

        $query = Rental::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->with(['customer:id,name', 'items', 'location:id,name'])
            ->whereNotNull('activated_at')
            ->whereBetween('activated_at', [$from, $to])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->whereIn('status', ['active', 'returned'])
            ->orderByDesc('activated_at');

        $paginator = $query->paginate(min(max($perPage, 1), 100), ['*'], 'page', max($page, 1));

        $paginator->getCollection()->transform(function (Rental $r) use ($today) {
            $status = $r->status;
            if ($status === 'active' && $r->expected_end_date && $r->expected_end_date->toDateString() < $today) {
                $status = 'overdue';
            }

            return [
                'id'               => $r->id,
                'rental_no'        => $r->rental_no,
                'customer_id'      => $r->customer_id,
                'customer_name'    => $r->customer?->name,
                'location_id'      => $r->location_id,
                'location_name'    => $r->location?->name,
                'items'            => $r->items->map(fn ($i) => [
                    'product_name' => $i->product_name,
                    'qty'          => round((float) $i->qty, 3),
                ])->values()->all(),
                'items_summary'    => $r->items->map(fn ($i) => $i->product_name.' × '.rtrim(rtrim(number_format((float) $i->qty, 3, '.', ''), '0'), '.'))->implode(', '),
                'delivery_date'    => optional($r->activated_at)->toDateString(),
                'deposit'          => round((float) $r->deposit, 2),
                'status'           => $status,
                'expected_end_date'=> optional($r->expected_end_date)->toDateString(),
            ];
        });

        return $paginator;
    }

    /**
     * Rental charges invoiced in a period (RentalInvoice rows from generateInvoice / settle).
     * Grouped by day|week|month and by branch/location.
     */
    public function rentalIncome(
        int|string $companyId,
        string $from,
        string $to,
        string $period = 'daily',
        ?string $locationId = null,
    ): array {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        if (! in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            $period = 'daily';
        }

        $invoices = RentalInvoice::query()
            ->forCompany($companyId)
            ->with(['rental:id,location_id,rental_no,customer_id', 'rental.location:id,name', 'rental.customer:id,name'])
            ->whereBetween('period_from', [$from, $to])
            ->when($locationId, fn ($q) => $q->whereHas('rental', fn ($rq) => $rq->where('location_id', $locationId)))
            ->orderBy('period_from')
            ->get();

        $total = round((float) $invoices->sum('amount'), 2);

        $byPeriod = $invoices->groupBy(function (RentalInvoice $inv) use ($period) {
            $d = Carbon::parse($inv->period_from);

            return match ($period) {
                'weekly'  => $d->copy()->startOfWeek()->toDateString(),
                'monthly' => $d->format('Y-m'),
                default   => $d->toDateString(),
            };
        })->map(fn ($group, $key) => [
            'period' => (string) $key,
            'count'  => $group->count(),
            'amount' => round((float) $group->sum('amount'), 2),
        ])->values()->all();

        $byBranch = $invoices->groupBy(fn (RentalInvoice $inv) => $inv->rental?->location_id ?? 'none')
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'location_id'   => $first->rental?->location_id,
                    'location_name' => $first->rental?->location?->name ?? 'Unassigned',
                    'count'         => $group->count(),
                    'amount'        => round((float) $group->sum('amount'), 2),
                ];
            })->values()->all();

        $rows = $invoices->map(fn (RentalInvoice $inv) => [
            'invoice_no'    => $inv->invoice_no,
            'rental_no'     => $inv->rental?->rental_no,
            'customer_name' => $inv->rental?->customer?->name,
            'location_name' => $inv->rental?->location?->name ?? 'Unassigned',
            'period_from'   => optional($inv->period_from)->toDateString(),
            'period_to'     => optional($inv->period_to)->toDateString(),
            'cycles'        => (float) $inv->cycles,
            'amount'        => round((float) $inv->amount, 2),
            'status'        => $inv->status,
        ])->values()->all();

        return [
            'from'      => $from,
            'to'        => $to,
            'period'    => $period,
            'location_id' => $locationId,
            'total'     => $total,
            'count'     => $invoices->count(),
            'by_period' => $byPeriod,
            'by_branch' => $byBranch,
            'rows'      => $rows,
        ];
    }

    /** Live snapshot of items still out on rent (no date filter). */
    public function rentalCurrent(
        int|string $companyId,
        ?string $locationId = null,
        int $perPage = 50,
        int $page = 1,
    ): LengthAwarePaginator {
        $today = Carbon::today();

        $rentals = Rental::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->with(['customer:id,name', 'items', 'location:id,name'])
            ->where('status', 'active')
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->orderBy('activated_at')
            ->get();

        $rows = [];
        foreach ($rentals as $r) {
            $delivery = $r->activated_at ? Carbon::parse($r->activated_at)->startOfDay() : Carbon::parse($r->start_date)->startOfDay();
            $daysElapsed = max(0, $delivery->diffInDays($today));
            $overdue = $r->expected_end_date && $r->expected_end_date->toDateString() < $today->toDateString();

            foreach ($r->items as $item) {
                $out = max(0, (float) $item->qty - (float) $item->returned_qty - (float) $item->damaged_qty - (float) $item->missing_qty);
                if ($out <= 0) {
                    continue;
                }
                $rows[] = [
                    'rental_id'        => $r->id,
                    'rental_no'        => $r->rental_no,
                    'customer_id'      => $r->customer_id,
                    'customer_name'    => $r->customer?->name,
                    'location_name'    => $r->location?->name,
                    'product_id'       => $item->product_id,
                    'product_name'     => $item->product_name,
                    'qty'              => round($out, 3),
                    'delivery_date'    => $delivery->toDateString(),
                    'days_elapsed'     => $daysElapsed,
                    'expected_end_date'=> optional($r->expected_end_date)->toDateString(),
                    'status'           => $overdue ? 'overdue' : 'expected',
                ];
            }
        }

        $total = count($rows);
        $perPage = min(max($perPage, 1), 100);
        $page = max($page, 1);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return new Paginator($slice, $total, $perPage, $page, [
            'path'  => Paginator::resolveCurrentPath(),
            'query' => request()->query(),
        ]);
    }

    /** Full rental history for one customer (past + current). */
    public function rentalCustomerHistory(
        int|string $companyId,
        string $customerId,
        ?string $locationId = null,
        ?string $from = null,
        ?string $to = null,
        int $perPage = 50,
        int $page = 1,
    ): LengthAwarePaginator {
        $query = Rental::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->with(['customer:id,name', 'items', 'invoices', 'location:id,name'])
            ->where('customer_id', $customerId)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->when($from, fn ($q) => $q->whereDate('start_date', '>=', Carbon::parse($from)->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('start_date', '<=', Carbon::parse($to)->toDateString()))
            ->whereIn('status', ['active', 'returned', 'cancelled', 'draft'])
            ->orderByDesc('start_date')
            ->orderByDesc('created_at');

        $paginator = $query->paginate(min(max($perPage, 1), 100), ['*'], 'page', max($page, 1));

        $paginator->getCollection()->transform(function (Rental $r) {
            $today = Carbon::today()->toDateString();
            $status = $r->status;
            if ($status === 'active' && $r->expected_end_date && $r->expected_end_date->toDateString() < $today) {
                $status = 'overdue';
            }

            return [
                'id'               => $r->id,
                'rental_no'        => $r->rental_no,
                'customer_id'      => $r->customer_id,
                'customer_name'    => $r->customer?->name,
                'location_name'    => $r->location?->name,
                'start_date'       => optional($r->start_date)->toDateString(),
                'delivery_date'    => optional($r->activated_at)->toDateString(),
                'expected_end_date'=> optional($r->expected_end_date)->toDateString(),
                'return_date'      => optional($r->return_date)->toDateString() ?? optional($r->returned_at)->toDateString(),
                'items'            => $r->items->map(fn ($i) => [
                    'product_name' => $i->product_name,
                    'qty'          => round((float) $i->qty, 3),
                    'returned_qty' => round((float) $i->returned_qty, 3),
                    'damaged_qty'  => round((float) $i->damaged_qty, 3),
                    'missing_qty'  => round((float) $i->missing_qty, 3),
                    'rate_per_cycle' => round((float) $i->rate_per_cycle, 2),
                ])->values()->all(),
                'items_summary'    => $r->items->map(fn ($i) => $i->product_name.' × '.rtrim(rtrim(number_format((float) $i->qty, 3, '.', ''), '0'), '.'))->implode(', '),
                'deposit'          => round((float) $r->deposit, 2),
                'rental_charge'    => round((float) $r->rental_charge, 2),
                'damage_charge'    => round((float) $r->damage_charge, 2),
                'missing_charge'   => round((float) $r->missing_charge, 2),
                'refund_amount'    => round((float) $r->refund_amount, 2),
                'balance_due'      => round((float) $r->balance_due, 2),
                'invoiced_total'   => round((float) $r->invoices->sum('amount'), 2),
                'status'           => $status,
            ];
        });

        return $paginator;
    }

    /** Revenue breakdown by price tier used at POS (retail / wholesale / dealer). */
    public function salesByPriceLevel(int|string $companyId, string $from, string $to): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        $rows = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.company_id', $companyId)
            ->where('sales.status', 'confirmed')
            ->whereBetween('sales.sale_date', [$from, $to])
            ->selectRaw("COALESCE(sale_items.price_level, 'retail') as price_level,
                COUNT(DISTINCT sales.id) as sale_count,
                SUM(sale_items.qty) as qty,
                SUM(sale_items.line_total) as revenue")
            ->groupBy('price_level')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => [
                'price_level' => $r->price_level,
                'label'       => match ($r->price_level) {
                    'wholesale' => 'Wholesale',
                    'dealer'    => 'Dealer / Landscaper',
                    default     => 'Retail',
                },
                'sale_count'  => (int) $r->sale_count,
                'qty'         => round((float) $r->qty, 3),
                'revenue'     => round((float) $r->revenue, 2),
            ])
            ->values()
            ->all();

        $total = round(collect($rows)->sum('revenue'), 2);

        return [
            'range'   => ['from' => $from, 'to' => $to],
            'rows'    => $rows,
            'total'   => $total,
        ];
    }

    public function productionSummary(int|string|null $companyId, string $from, string $to, int $perPage = 50, int $page = 1): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        $base = ProductionOrder::query()
            ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
            ->where('status', 'completed');
        $base = $this->applyDateRange($base, 'order_date', $from, $to);

        $completed = (clone $base)->count();
        $outputQty = round((float) (clone $base)->sum('output_quantity'), 3);
        $totalCost = round((float) (clone $base)->sum('total_input_cost'), 2);

        $paginator = (clone $base)
            ->with(['outputProduct:id,name', 'supervisor:id,name', 'location:id,name'])
            ->orderByDesc('order_date')
            ->paginate(min(max($perPage, 1), 100), ['*'], 'page', max($page, 1));

        $paginator->getCollection()->transform(fn (ProductionOrder $o) => [
            'id'               => $o->id,
            'order_no'         => $o->order_no,
            'order_date'       => optional($o->order_date)->toDateString(),
            'output_product'   => $o->outputProduct?->name,
            'output_quantity'  => round((float) $o->output_quantity, 3),
            'total_input_cost' => round((float) $o->total_input_cost, 2),
            'output_unit_cost' => round((float) $o->output_unit_cost, 4),
            'supervisor'       => $o->supervisor?->name,
            'location'         => $o->location?->name,
        ]);

        return [
            'summary' => [
                'completed'    => $completed,
                'output_qty'   => $outputQty,
                'total_cost'   => $totalCost,
                'avg_unit_cost'=> $outputQty > 0 ? round($totalCost / $outputQty, 4) : 0,
            ],
            'orders'  => $paginator,
        ];
    }

    public function productionByProduct(int|string|null $companyId, string $from, string $to): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        $rows = $this->applyDateRange(
            ProductionOrder::query()
                ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
                ->where('status', 'completed'),
            'order_date',
            $from,
            $to,
        )
            ->join('products', 'products.id', '=', 'production_orders.output_product_id')
            ->selectRaw('products.id as product_id, products.name as product_name, products.sku,
                COUNT(*) as run_count,
                SUM(production_orders.output_quantity) as output_qty,
                SUM(production_orders.total_input_cost) as total_cost')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('total_cost')
            ->get()
            ->map(fn ($r) => [
                'product_id'   => $r->product_id,
                'product_name' => $r->product_name,
                'sku'          => $r->sku,
                'run_count'    => (int) $r->run_count,
                'output_qty'   => round((float) $r->output_qty, 3),
                'total_cost'   => round((float) $r->total_cost, 2),
                'avg_unit_cost'=> (float) $r->output_qty > 0
                    ? round((float) $r->total_cost / (float) $r->output_qty, 4)
                    : 0,
            ])
            ->values()
            ->all();

        return ['rows' => $rows, 'total_cost' => round(collect($rows)->sum('total_cost'), 2)];
    }

    public function productionBySupervisor(int|string|null $companyId, string $from, string $to): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        $rows = $this->applyDateRange(
            ProductionOrder::query()
                ->when($companyId !== null, fn ($q) => $q->forCompany($companyId))
                ->where('status', 'completed'),
            'order_date',
            $from,
            $to,
        )
            ->leftJoin('users', 'users.id', '=', 'production_orders.supervisor_id')
            ->selectRaw('production_orders.supervisor_id, COALESCE(users.name, \'Unassigned\') as supervisor_name,
                COUNT(*) as run_count,
                SUM(production_orders.output_quantity) as output_qty,
                SUM(production_orders.total_input_cost) as total_cost')
            ->groupBy('production_orders.supervisor_id', 'users.name')
            ->orderByDesc('total_cost')
            ->get()
            ->map(fn ($r) => [
                'supervisor_id'   => $r->supervisor_id,
                'supervisor_name' => $r->supervisor_name,
                'run_count'       => (int) $r->run_count,
                'output_qty'      => round((float) $r->output_qty, 3),
                'total_cost'      => round((float) $r->total_cost, 2),
            ])
            ->values()
            ->all();

        return ['rows' => $rows, 'total_cost' => round(collect($rows)->sum('total_cost'), 2)];
    }

    public function productionBatches(
        int|string|null $companyId,
        string $from,
        string $to,
        int $perPage = 50,
        int $page = 1,
    ): LengthAwarePaginator {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->endOfDay();

        $query = ProductBatch::query()
            ->when($companyId !== null, fn ($q) => $q->where('product_batches.company_id', $companyId))
            ->whereNotNull('production_order_id')
            ->whereBetween('product_batches.received_at', [$from, $to])
            ->with(['product:id,sku,name', 'productionOrder:id,order_no,supervisor_id', 'productionOrder.supervisor:id,name', 'location:id,name'])
            ->orderByDesc('product_batches.received_at');

        $paginator = $query->paginate(min(max($perPage, 1), 100), ['*'], 'page', max($page, 1));

        $paginator->getCollection()->transform(fn (ProductBatch $b) => [
            'id'            => $b->id,
            'batch_no'      => $b->batch_no,
            'barcode'       => $b->barcode,
            'product_name'  => $b->product?->name,
            'sku'           => $b->product?->sku,
            'qty'           => round((float) $b->qty, 3),
            'remaining_qty' => round((float) $b->remaining_qty, 3),
            'cost_price'    => round((float) $b->cost_price, 4),
            'order_no'      => $b->productionOrder?->order_no,
            'supervisor'    => $b->productionOrder?->supervisor?->name,
            'location'      => $b->location?->name,
            'received_at'   => optional($b->received_at)->toDateString(),
        ]);

        return $paginator;
    }

    public function transferSummary(
        int|string|null $companyId,
        string $from,
        string $to,
        int $perPage = 50,
        int $page = 1,
    ): array {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        $base = StockTransfer::query()
            ->when($companyId !== null, fn ($q) => $q->where(fn ($inner) => $inner->where('company_id', $companyId)->orWhere('to_company_id', $companyId)));
        $base = $this->applyDateRange($base, 'transfer_date', $from, $to);

        $summary = [
            'total'          => (clone $base)->count(),
            'received'       => (clone $base)->where('status', 'received')->count(),
            'in_transit'     => (clone $base)->where('status', 'in_transit')->count(),
            'requested'      => (clone $base)->where('status', 'requested')->count(),
            'inter_company'  => (clone $base)->where('transfer_type', 'inter_company')->count(),
            'intra_company'  => (clone $base)->where('transfer_type', 'intra_company')->count(),
        ];

        $paginator = (clone $base)
            ->with(['fromCompany:id,name', 'toCompany:id,name', 'fromLocation:id,name', 'toLocation:id,name'])
            ->withCount('items')
            ->orderByDesc('transfer_date')
            ->orderByDesc('created_at')
            ->paginate(min(max($perPage, 1), 100), ['*'], 'page', max($page, 1));

        $paginator->getCollection()->transform(function (StockTransfer $t) {
            $route = $t->isIntraCompany()
                ? ($t->fromLocation?->name ?? 'Source').' → '.($t->toLocation?->name ?? 'Destination')
                : ($t->fromCompany?->name ?? 'Source').' → '.($t->toCompany?->name ?? 'Destination');

            return [
                'id'            => $t->id,
                'transfer_no'   => $t->transfer_no,
                'transfer_date' => optional($t->transfer_date)->toDateString(),
                'transfer_type' => $t->transfer_type,
                'route'         => $route,
                'items_count'   => (int) $t->items_count,
                'status'        => $t->status,
                'dispatched_at' => optional($t->dispatched_at)->toIso8601String(),
                'received_at'   => optional($t->received_at)->toIso8601String(),
            ];
        });

        return ['summary' => $summary, 'orders' => $paginator];
    }

    /** Live snapshot of transfers currently in transit. */
    public function transferInTransit(
        int|string|null $companyId,
        int $perPage = 50,
        int $page = 1,
    ): LengthAwarePaginator {
        $query = StockTransfer::query()
            ->where('status', 'in_transit')
            ->when($companyId !== null, fn ($q) => $q->where(fn ($inner) => $inner->where('company_id', $companyId)->orWhere('to_company_id', $companyId)))
            ->with(['fromCompany:id,name', 'toCompany:id,name', 'fromLocation:id,name', 'toLocation:id,name', 'items'])
            ->orderByDesc('dispatched_at')
            ->orderByDesc('transfer_date');

        $paginator = $query->paginate(min(max($perPage, 1), 100), ['*'], 'page', max($page, 1));

        $paginator->getCollection()->transform(function (StockTransfer $t) {
            $route = $t->isIntraCompany()
                ? ($t->fromLocation?->name ?? 'Source').' → '.($t->toLocation?->name ?? 'Destination')
                : ($t->fromCompany?->name ?? 'Source').' → '.($t->toCompany?->name ?? 'Destination');
            $qty = round((float) $t->items->sum(fn ($i) => $i->dispatchQty()), 3);

            return [
                'id'            => $t->id,
                'transfer_no'   => $t->transfer_no,
                'transfer_date' => optional($t->transfer_date)->toDateString(),
                'transfer_type' => $t->transfer_type,
                'route'         => $route,
                'qty'           => $qty,
                'items_summary' => $t->items->map(fn ($i) => $i->product_name.' × '.rtrim(rtrim(number_format($i->dispatchQty(), 3, '.', ''), '0'), '.'))->implode(', '),
                'dispatched_at' => optional($t->dispatched_at)->toIso8601String(),
                'days_in_transit' => $t->dispatched_at ? max(0, Carbon::parse($t->dispatched_at)->startOfDay()->diffInDays(Carbon::today())) : 0,
            ];
        });

        return $paginator;
    }

    private const CASH_MODES = ['cash'];

    private const BANK_MODES = ['bank', 'upi', 'cheque', 'card'];

    /** Cash register with running balance. */
    public function cashBook(int|string $companyId, string $from, string $to, int $page = 1, int $perPage = 50): array
    {
        return $this->moneyBook($companyId, $from, $to, self::CASH_MODES, 'cash_opening_balance', $page, $perPage);
    }

    /** Bank register (card/UPI/cheque/bank transfers). */
    public function bankBook(int|string $companyId, string $from, string $to, int $page = 1, int $perPage = 50): array
    {
        return $this->moneyBook($companyId, $from, $to, self::BANK_MODES, 'bank_opening_balance', $page, $perPage);
    }

    /** Customer statement — debits (credit sales) and credits (receipts). */
    public function debtorLedger(int|string $companyId, string $customerId, string $from, string $to): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        $customer = Customer::forCompany($companyId)->findOrFail($customerId);

        $opening = $this->debtorBalanceAsOf($companyId, $customerId, Carbon::parse($from)->subDay()->toDateString());

        $rows = collect();

        Sale::forCompany($companyId)
            ->where('customer_id', $customerId)
            ->where('status', 'confirmed')
            ->where('payment_mode', 'credit')
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to)
            ->orderBy('sale_date')
            ->get(['id', 'sale_no', 'sale_date', 'grand_total', 'loyalty_discount'])
            ->each(function (Sale $s) use ($rows) {
                $rows->push([
                    'date'        => $s->sale_date->toDateString(),
                    'type'        => 'debit',
                    'reference'   => $s->sale_no,
                    'description' => 'Credit sale',
                    'amount'      => round(max(0, (float) $s->grand_total - (float) $s->loyalty_discount), 2),
                ]);
            });

        CustomerReceipt::forCompany($companyId)
            ->where('customer_id', $customerId)
            ->whereNull('advance_order_id')
            ->whereDate('receipt_date', '>=', $from)
            ->whereDate('receipt_date', '<=', $to)
            ->orderBy('receipt_date')
            ->get(['receipt_no', 'receipt_date', 'amount'])
            ->each(function (CustomerReceipt $r) use ($rows) {
                $rows->push([
                    'date'        => $r->receipt_date->toDateString(),
                    'type'        => 'credit',
                    'reference'   => $r->receipt_no,
                    'description' => 'Receipt',
                    'amount'      => (float) $r->amount,
                ]);
            });

        $balance = $opening;
        $entries = $rows->sortBy(fn ($r) => $r['date'].$r['reference'])->values()->map(function ($row) use (&$balance) {
            $balance += $row['type'] === 'debit' ? $row['amount'] : -$row['amount'];
            $row['balance'] = round($balance, 2);

            return $row;
        });

        return [
            'customer'         => ['id' => $customer->id, 'name' => $customer->name],
            'opening_balance'  => round($opening, 2),
            'closing_balance'  => round($balance, 2),
            'current_outstanding' => round((float) $customer->outstanding, 2),
            'advance_balance'  => round((float) $customer->advance_balance, 2),
            'rows'             => $entries->all(),
        ];
    }

    /** Supplier statement — credits (purchases) and debits (payments). */
    public function creditorLedger(int|string $companyId, string $supplierId, string $from, string $to): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        $supplier = Supplier::forCompany($companyId)->findOrFail($supplierId);

        $opening = $this->creditorBalanceAsOf($companyId, $supplierId, Carbon::parse($from)->subDay()->toDateString());

        $rows = collect();

        Purchase::forCompany($companyId)
            ->where('supplier_id', $supplierId)
            ->where('status', 'confirmed')
            ->whereDate('purchase_date', '>=', $from)
            ->whereDate('purchase_date', '<=', $to)
            ->orderBy('purchase_date')
            ->get(['purchase_no', 'purchase_date', 'grand_total'])
            ->each(function (Purchase $p) use ($rows) {
                $rows->push([
                    'date'        => $p->purchase_date->toDateString(),
                    'type'        => 'credit',
                    'reference'   => $p->purchase_no,
                    'description' => 'Purchase',
                    'amount'      => (float) $p->grand_total,
                ]);
            });

        SupplierPayment::forCompany($companyId)
            ->where('supplier_id', $supplierId)
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->orderBy('payment_date')
            ->get(['payment_no', 'payment_date', 'amount'])
            ->each(function (SupplierPayment $p) use ($rows) {
                $rows->push([
                    'date'        => $p->payment_date->toDateString(),
                    'type'        => 'debit',
                    'reference'   => $p->payment_no,
                    'description' => 'Payment',
                    'amount'      => (float) $p->amount,
                ]);
            });

        $balance = $opening;
        $entries = $rows->sortBy(fn ($r) => $r['date'].$r['reference'])->values()->map(function ($row) use (&$balance) {
            $balance += $row['type'] === 'credit' ? $row['amount'] : -$row['amount'];
            $row['balance'] = round($balance, 2);

            return $row;
        });

        return [
            'supplier'            => ['id' => $supplier->id, 'name' => $supplier->name],
            'opening_balance'     => round($opening, 2),
            'closing_balance'     => round($balance, 2),
            'current_outstanding' => round((float) $supplier->outstanding, 2),
            'rows'                => $entries->all(),
        ];
    }

    /** Ageing buckets for open receivables. */
    public function ageingReceivables(int|string|null $companyId): array
    {
        return $this->ageingFromItems($this->receipts->receivables($companyId), 'customer_name');
    }

    /** Ageing buckets for open payables. */
    public function ageingPayables(int|string|null $companyId): array
    {
        return $this->ageingFromItems($this->payments->payables($companyId), 'supplier_name');
    }

    /** @param list<string> $modes */
    private function moneyBook(
        int|string $companyId,
        string $from,
        string $to,
        array $modes,
        string $openingSettingKey,
        int $page,
        int $perPage,
    ): array {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        $perPage = min(max($perPage, 1), 100);
        $page = max($page, 1);

        $opening = $this->settings->getFloat($companyId, $openingSettingKey);
        $opening += $this->moneyNetBefore($companyId, $modes, $from);

        $rows = collect();

        CustomerReceipt::forCompany($companyId)
            ->whereIn('mode', $modes)
            ->whereDate('receipt_date', '>=', $from)
            ->whereDate('receipt_date', '<=', $to)
            ->with('customer:id,name')
            ->get()
            ->each(fn (CustomerReceipt $r) => $rows->push([
                'date' => $r->receipt_date->toDateString(),
                'type' => 'in',
                'reference' => $r->receipt_no,
                'party' => $r->customer?->name,
                'description' => $r->advance_order_id ? 'Advance receipt' : 'Customer receipt',
                'amount' => (float) $r->amount,
            ]));

        SupplierPayment::forCompany($companyId)
            ->whereIn('mode', $modes)
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->with('supplier:id,name')
            ->get()
            ->each(fn (SupplierPayment $p) => $rows->push([
                'date' => $p->payment_date->toDateString(),
                'type' => 'out',
                'reference' => $p->payment_no,
                'party' => $p->supplier?->name,
                'description' => 'Supplier payment',
                'amount' => (float) $p->amount,
            ]));

        CommissionPayout::forCompany($companyId)
            ->where('status', 'paid')
            ->whereIn('mode', $modes)
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->with('user:id,name')
            ->get()
            ->each(fn (CommissionPayout $p) => $rows->push([
                'date' => $p->payment_date?->toDateString() ?? $from,
                'type' => 'out',
                'reference' => $p->period,
                'party' => $p->user?->name,
                'description' => 'Commission payout',
                'amount' => (float) $p->amount,
            ]));

        $balance = $opening;
        $sorted = $rows->sortBy(fn ($r) => $r['date'].$r['reference'])->values()->map(function ($row) use (&$balance) {
            $balance += $row['type'] === 'in' ? $row['amount'] : -$row['amount'];
            $row['balance'] = round($balance, 2);

            return $row;
        });

        $total = $sorted->count();
        $slice = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'from'             => $from,
            'to'               => $to,
            'opening_balance'  => round($opening, 2),
            'closing_balance'  => round($balance, 2),
            'total_in'         => round($sorted->where('type', 'in')->sum('amount'), 2),
            'total_out'        => round($sorted->where('type', 'out')->sum('amount'), 2),
            'rows'             => $slice->all(),
            'meta'             => [
                'current_page' => $page,
                'last_page'    => (int) max(1, ceil($total / $perPage)),
                'per_page'     => $perPage,
                'total'        => $total,
            ],
        ];
    }

    private function moneyNetBefore(int|string $companyId, array $modes, string $beforeDate): float
    {
        $in = (float) CustomerReceipt::forCompany($companyId)->whereIn('mode', $modes)
            ->whereDate('receipt_date', '<', $beforeDate)->sum('amount');
        $outPay = (float) SupplierPayment::forCompany($companyId)->whereIn('mode', $modes)
            ->whereDate('payment_date', '<', $beforeDate)->sum('amount');
        $outComm = (float) CommissionPayout::forCompany($companyId)->where('status', 'paid')->whereIn('mode', $modes)
            ->whereDate('payment_date', '<', $beforeDate)->sum('amount');

        return $in - $outPay - $outComm;
    }

    private function debtorBalanceAsOf(int|string $companyId, string $customerId, string $asOf): float
    {
        $customer = Customer::forCompany($companyId)->findOrFail($customerId);
        $balance = (float) $customer->opening_balance;

        $sales = (float) Sale::forCompany($companyId)
            ->where('customer_id', $customerId)
            ->where('status', 'confirmed')
            ->where('payment_mode', 'credit')
            ->whereDate('sale_date', '<=', $asOf)
            ->selectRaw('SUM(grand_total - loyalty_discount) as total')
            ->value('total');

        $receipts = (float) CustomerReceipt::forCompany($companyId)
            ->where('customer_id', $customerId)
            ->whereNull('advance_order_id')
            ->whereDate('receipt_date', '<=', $asOf)
            ->sum('amount');

        return $balance + $sales - $receipts;
    }

    private function creditorBalanceAsOf(int|string $companyId, string $supplierId, string $asOf): float
    {
        $supplier = Supplier::forCompany($companyId)->findOrFail($supplierId);
        $balance = (float) $supplier->opening_balance;

        $purchases = (float) Purchase::forCompany($companyId)
            ->where('supplier_id', $supplierId)
            ->where('status', 'confirmed')
            ->whereDate('purchase_date', '<=', $asOf)
            ->sum('grand_total');

        $payments = (float) SupplierPayment::forCompany($companyId)
            ->where('supplier_id', $supplierId)
            ->whereDate('payment_date', '<=', $asOf)
            ->sum('amount');

        return $balance + $purchases - $payments;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function ageingFromItems(array $items, string $partyKey): array
    {
        $buckets = [
            'current'  => ['label' => 'Current', 'total' => 0.0, 'count' => 0],
            '1_30'     => ['label' => '1–30 days', 'total' => 0.0, 'count' => 0],
            '31_60'    => ['label' => '31–60 days', 'total' => 0.0, 'count' => 0],
            '61_90'    => ['label' => '61–90 days', 'total' => 0.0, 'count' => 0],
            '90_plus'  => ['label' => '90+ days', 'total' => 0.0, 'count' => 0],
        ];
        $lines = [];

        foreach ($items as $item) {
            $bal = (float) ($item['balance'] ?? 0);
            if ($bal <= 0.005) {
                continue;
            }
            $due = filled($item['due_date'] ?? null) ? Carbon::parse($item['due_date']) : null;
            $days = $due ? $due->diffInDays(Carbon::today(), false) : 0;
            $key = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => '90_plus',
            };
            $buckets[$key]['total'] += $bal;
            $buckets[$key]['count']++;
            $lines[] = array_merge($item, ['days_overdue' => max(0, $days), 'bucket' => $key]);
        }

        return [
            'buckets' => collect($buckets)->map(fn ($b, $k) => [
                'key' => $k, 'label' => $b['label'], 'total' => round($b['total'], 2), 'count' => $b['count'],
            ])->values()->all(),
            'lines'   => $lines,
            'total'   => round(collect($lines)->sum('balance'), 2),
        ];
    }

    /** Inclusive range for DATE columns (SQLite stores them as datetimes). */
    private function applyDateRange($query, string $column, string $from, string $to)
    {
        return $query->whereDate($column, '>=', $from)->whereDate($column, '<=', $to);
    }
}
