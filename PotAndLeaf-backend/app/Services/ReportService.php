<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Purchase;
use App\Models\Rental;
use App\Models\RentalInvoice;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SettingsService $settings,
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
        $base = ProductionOrder::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))->where('status', 'completed')->whereBetween('order_date', [$from, $to]);

        return [
            'completed'    => (clone $base)->count(),
            'output_value' => round((float) (clone $base)->sum('total_input_cost'), 2),
        ];
    }

    private function rentals(int|string $companyId, string $from, string $to): array
    {
        return [
            'active'   => Rental::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))->where('status', 'active')->count(),
            'invoiced' => round((float) RentalInvoice::query()->when($companyId !== null, fn ($q) => $q->forCompany($companyId))->whereBetween('period_from', [$from, $to])->sum('amount'), 2),
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
}
