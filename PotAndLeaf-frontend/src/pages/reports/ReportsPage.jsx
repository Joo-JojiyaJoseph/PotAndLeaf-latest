import { useMemo, useState, useEffect } from 'react';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { useCompanyFilter } from '../../hooks/useCompanyFilter';
import { useToast } from '../../lib/toast';
import { Card, StatCard, Spinner, Badge } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';
import { downloadWithParams } from '../../lib/pdfDownload';
import AccountingReportPanels from './AccountingReportPanels';
import ReportsToolbar from './ReportsToolbar';
import ReportNavGrid from './ReportNavGrid';
import ReportEmptyState from './ReportEmptyState';
import { REPORT_TABS, filterVisibleTabs, tabMeta } from './reportConfig';

const iso = (d) => d.toISOString().slice(0, 10);
const daysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return iso(d); };
const selectCls = 'h-9 rounded-lg border border-line bg-surface px-2 text-sm';
const statusTone = { active: 'active', returned: 'inactive', overdue: 'blocked', expected: 'warning', cancelled: 'blocked', draft: 'inactive', requested: 'warning', in_transit: 'info', received: 'active', rejected: 'blocked' };

function TrendChart({ data }) {
  const max = Math.max(1, ...data.map((d) => d.total));
  const W = 720, H = 200, pad = 28;
  const n = data.length;
  const bw = n > 0 ? (W - pad * 2) / n : 0;
  if (n === 0) return <div className="py-12 text-center text-sm text-muted">No sales in this range.</div>;
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="w-full" preserveAspectRatio="xMidYMid meet">
      <line x1={pad} y1={H - pad} x2={W - pad} y2={H - pad} stroke="var(--color-line, #e5e7eb)" />
      {data.map((d, i) => {
        const h = ((d.total / max) * (H - pad * 2));
        const x = pad + i * bw + bw * 0.15;
        const y = H - pad - h;
        return (
          <g key={d.date}>
            <rect x={x} y={y} width={bw * 0.7} height={Math.max(0, h)} rx="2" fill="var(--color-leaf, #4a7c59)" opacity="0.85">
              <title>{`${d.date}: ${formatCurrency(d.total)}`}</title>
            </rect>
          </g>
        );
      })}
      <text x={pad} y={H - 6} fontSize="10" fill="var(--color-muted, #9ca3af)">{data[0]?.date}</text>
      <text x={W - pad} y={H - 6} fontSize="10" textAnchor="end" fill="var(--color-muted, #9ca3af)">{data[n - 1]?.date}</text>
    </svg>
  );
}


export default function ReportsPage() {
  const { activeCompany, can, isSuperAdmin, user } = useAuth();
  const { filterCompanyId, setFilterCompanyId, companyParams, viewingCompany } = useCompanyFilter();
  const toast = useToast();
  const [tab, setTab] = useState('dashboard');
  const [range, setRange] = useState({ from: daysAgo(29), to: iso(new Date()) });
  const [showCustomDates, setShowCustomDates] = useState(false);
  const [reportSearch, setReportSearch] = useState('');
  const [period, setPeriod] = useState('daily');
  const [sortKey, setSortKey] = useState('margin_pct');
  const [locationId, setLocationId] = useState('');
  const [customerId, setCustomerId] = useState('');
  const [supplierId, setSupplierId] = useState('');
  const [movementDays, setMovementDays] = useState(30);
  const [movementClass, setMovementClass] = useState('all');

  const locationParam = locationId || undefined;

  const canHo = isSuperAdmin || can('reports.margin') || can('reports.profit') || can('products.view_cost') || can('*');
  const canRentalReports = isSuperAdmin || can('*') || (can('reports.view') && can('rental.view'));
  const canProductionReports = isSuperAdmin || can('*') || (can('reports.view') && can('production.view'));
  const canTransferReports = isSuperAdmin || can('*') || (can('reports.view') && can('transfers.view'));
  const canAccounting = isSuperAdmin || can('*') || (can('reports.view') && (can('receipts.view') || can('payments.view')));
  const canCommissionReport = isSuperAdmin || can('*') || (can('reports.view') && can('commission.view'));
  const canInventory = isSuperAdmin || can('*') || (can('reports.view') && can('inventory.view'));
  const isRentalTab = tab.startsWith('rental_');
  const isProductionTab = tab.startsWith('production_');
  const isTransferTab = tab.startsWith('transfer_');
  const isAccountingTab = ['cash_book', 'bank_book', 'debtor_ledger', 'creditor_ledger', 'ageing_receivables', 'ageing_payables'].includes(tab);
  const hideDateRange = tab === 'rental_current' || tab === 'transfer_in_transit' || tab === 'ageing_receivables' || tab === 'ageing_payables' || tab === 'sales_analytics' || tab === 'leaderboard' || tab === 'inventory_movement';
  const [leaderboardPeriod, setLeaderboardPeriod] = useState('month');

  const visibleTabs = useMemo(() => filterVisibleTabs(REPORT_TABS, {
    canHo, canRentalReports, canProductionReports, canTransferReports, canAccounting, canCommissionReport, canInventory,
  }), [canHo, canRentalReports, canProductionReports, canTransferReports, canAccounting, canCommissionReport, canInventory]);

  const { data: formData } = useQuery({
    queryKey: ['reports-form-data', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/reports/form-data', { params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });

  useEffect(() => {
    setLocationId('');
    setCustomerId('');
    setSupplierId('');
  }, [filterCompanyId]);

  const dashQ = useQuery({
    queryKey: ['reports-dashboard', activeCompany?.id, filterCompanyId, range.from, range.to],
    queryFn: () => api.get('/reports/dashboard', { params: { ...range, ...companyParams } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'dashboard',
    placeholderData: keepPreviousData,
  });

  const marginQ = useQuery({
    queryKey: ['reports-margin', activeCompany?.id, filterCompanyId, range.from, range.to],
    queryFn: () => api.get('/reports/margin', { params: { ...range, ...companyParams } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'margin' && canHo,
    placeholderData: keepPreviousData,
  });

  const profitQ = useQuery({
    queryKey: ['reports-profit', activeCompany?.id, filterCompanyId, range.from, range.to, period],
    queryFn: () => api.get('/reports/profit', {
      params: { ...range, period, ...companyParams },
    }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'profit' && canHo,
    placeholderData: keepPreviousData,
  });

  const priceLevelQ = useQuery({
    queryKey: ['reports-price-levels', activeCompany?.id, filterCompanyId, range.from, range.to],
    queryFn: () => api.get('/reports/price-levels', { params: { ...range, ...companyParams } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'price_levels',
    placeholderData: keepPreviousData,
  });

  const rentalDeliveryQ = useQuery({
    queryKey: ['reports-rental-delivery', activeCompany?.id, range.from, range.to, locationId],
    queryFn: () => api.get('/reports/rental/delivery', { params: { ...range, location_id: locationParam } }).then((r) => r.data),
    enabled: Boolean(activeCompany) && tab === 'rental_delivery' && canRentalReports,
    placeholderData: keepPreviousData,
  });

  const rentalIncomeQ = useQuery({
    queryKey: ['reports-rental-income', activeCompany?.id, range.from, range.to, locationId, period],
    queryFn: () => api.get('/reports/rental/income', { params: { ...range, location_id: locationParam, period } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'rental_income' && canRentalReports,
    placeholderData: keepPreviousData,
  });

  const rentalCurrentQ = useQuery({
    queryKey: ['reports-rental-current', activeCompany?.id, locationId],
    queryFn: () => api.get('/reports/rental/current', { params: { location_id: locationParam } }).then((r) => r.data),
    enabled: Boolean(activeCompany) && tab === 'rental_current' && canRentalReports,
    placeholderData: keepPreviousData,
  });

  const rentalCustomerQ = useQuery({
    queryKey: ['reports-rental-customer', activeCompany?.id, customerId, range.from, range.to, locationId],
    queryFn: () => api.get(`/reports/rental/customer/${customerId}`, { params: { ...range, location_id: locationParam } }).then((r) => r.data),
    enabled: Boolean(activeCompany) && tab === 'rental_customer' && canRentalReports && Boolean(customerId),
    placeholderData: keepPreviousData,
  });

  const productionSummaryQ = useQuery({
    queryKey: ['reports-production-summary', activeCompany?.id, filterCompanyId, range.from, range.to],
    queryFn: () => api.get('/reports/production/summary', { params: { ...range, ...companyParams } }).then((r) => r.data),
    enabled: Boolean(activeCompany) && tab === 'production_summary' && canProductionReports,
    placeholderData: keepPreviousData,
  });

  const productionByProductQ = useQuery({
    queryKey: ['reports-production-product', activeCompany?.id, filterCompanyId, range.from, range.to],
    queryFn: () => api.get('/reports/production/by-product', { params: { ...range, ...companyParams } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'production_by_product' && canProductionReports,
    placeholderData: keepPreviousData,
  });

  const productionBySupervisorQ = useQuery({
    queryKey: ['reports-production-supervisor', activeCompany?.id, filterCompanyId, range.from, range.to],
    queryFn: () => api.get('/reports/production/by-supervisor', { params: { ...range, ...companyParams } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'production_by_supervisor' && canProductionReports,
    placeholderData: keepPreviousData,
  });

  const productionBatchesQ = useQuery({
    queryKey: ['reports-production-batches', activeCompany?.id, filterCompanyId, range.from, range.to],
    queryFn: () => api.get('/reports/production/batches', { params: { ...range, ...companyParams } }).then((r) => r.data),
    enabled: Boolean(activeCompany) && tab === 'production_batches' && canProductionReports,
    placeholderData: keepPreviousData,
  });

  const transferSummaryQ = useQuery({
    queryKey: ['reports-transfer-summary', activeCompany?.id, filterCompanyId, range.from, range.to],
    queryFn: () => api.get('/reports/transfers/summary', { params: { ...range, ...companyParams } }).then((r) => r.data),
    enabled: Boolean(activeCompany) && tab === 'transfer_summary' && canTransferReports,
    placeholderData: keepPreviousData,
  });

  const transferInTransitQ = useQuery({
    queryKey: ['reports-transfer-in-transit', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/reports/transfers/in-transit', { params: { ...companyParams } }).then((r) => r.data),
    enabled: Boolean(activeCompany) && tab === 'transfer_in_transit' && canTransferReports,
    placeholderData: keepPreviousData,
  });

  const cashBookQ = useQuery({
    queryKey: ['reports-cash-book', activeCompany?.id, filterCompanyId, range.from, range.to],
    queryFn: () => api.get('/reports/accounting/cash-book', { params: { ...range, ...companyParams } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'cash_book' && canAccounting,
    placeholderData: keepPreviousData,
  });

  const bankBookQ = useQuery({
    queryKey: ['reports-bank-book', activeCompany?.id, filterCompanyId, range.from, range.to],
    queryFn: () => api.get('/reports/accounting/bank-book', { params: { ...range, ...companyParams } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'bank_book' && canAccounting,
    placeholderData: keepPreviousData,
  });

  const debtorLedgerQ = useQuery({
    queryKey: ['reports-debtor-ledger', activeCompany?.id, customerId, range.from, range.to],
    queryFn: () => api.get('/reports/accounting/debtor-ledger', { params: { ...range, customer_id: customerId } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'debtor_ledger' && canAccounting && Boolean(customerId),
    placeholderData: keepPreviousData,
  });

  const creditorLedgerQ = useQuery({
    queryKey: ['reports-creditor-ledger', activeCompany?.id, supplierId, range.from, range.to],
    queryFn: () => api.get('/reports/accounting/creditor-ledger', { params: { ...range, supplier_id: supplierId } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'creditor_ledger' && canAccounting && Boolean(supplierId),
    placeholderData: keepPreviousData,
  });

  const ageingRecQ = useQuery({
    queryKey: ['reports-ageing-ar', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/reports/accounting/ageing-receivables', { params: { ...companyParams } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'ageing_receivables' && canAccounting,
    placeholderData: keepPreviousData,
  });

  const ageingPayQ = useQuery({
    queryKey: ['reports-ageing-ap', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/reports/accounting/ageing-payables', { params: { ...companyParams } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'ageing_payables' && canAccounting,
    placeholderData: keepPreviousData,
  });

  const salesMonthQ = useQuery({
    queryKey: ['reports-sales-month', activeCompany?.id, filterCompanyId, locationId],
    queryFn: () => api.get('/reports/sales/comparison-month', { params: { ...companyParams, location_id: locationParam } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'sales_analytics',
    placeholderData: keepPreviousData,
  });

  const salesYoyQ = useQuery({
    queryKey: ['reports-sales-yoy', activeCompany?.id, filterCompanyId, locationId],
    queryFn: () => api.get('/reports/sales/comparison-yoy', { params: { ...companyParams, location_id: locationParam } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'sales_analytics',
    placeholderData: keepPreviousData,
  });

  const salesYtdQ = useQuery({
    queryKey: ['reports-sales-ytd', activeCompany?.id, filterCompanyId, locationId],
    queryFn: () => api.get('/reports/sales/ytd', { params: { ...companyParams, location_id: locationParam } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'sales_analytics',
    placeholderData: keepPreviousData,
  });

  const salesTrendQ = useQuery({
    queryKey: ['reports-sales-trend', activeCompany?.id, filterCompanyId, locationId],
    queryFn: () => api.get('/reports/sales/twelve-month-trend', { params: { ...companyParams, location_id: locationParam } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'sales_analytics',
    placeholderData: keepPreviousData,
  });

  const gstQ = useQuery({
    queryKey: ['reports-gst', activeCompany?.id, filterCompanyId, range.from, range.to],
    queryFn: () => api.get('/reports/gst-reconciliation', { params: { ...range, ...companyParams } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'gst_reconciliation' && canAccounting,
    placeholderData: keepPreviousData,
  });

  const commissionReportQ = useQuery({
    queryKey: ['reports-commission', activeCompany?.id, filterCompanyId, range.from, range.to],
    queryFn: () => api.get('/reports/commission', { params: { ...range, ...companyParams } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'commission_report' && canCommissionReport,
    placeholderData: keepPreviousData,
  });

  const leaderboardQ = useQuery({
    queryKey: ['reports-leaderboard', activeCompany?.id, filterCompanyId, leaderboardPeriod, locationId],
    queryFn: () => api.get('/reports/leaderboard', {
      params: { ...companyParams, period: leaderboardPeriod, location_id: locationParam },
    }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'leaderboard',
    placeholderData: keepPreviousData,
  });

  const movementQ = useQuery({
    queryKey: ['reports-inventory-movement', activeCompany?.id, filterCompanyId, movementDays],
    queryFn: () => api.get('/inventory/movement', { params: { ...companyParams, days: movementDays } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'inventory_movement' && canInventory,
    placeholderData: keepPreviousData,
  });

  const movementRows = useMemo(() => {
    const items = movementQ.data?.items ?? [];
    if (movementClass === 'all') return items;
    return items.filter((i) => i.class === movementClass);
  }, [movementQ.data, movementClass]);

  const exportOptions = useMemo(() => {
    const base = { ...range, ...companyParams };
    const opts = [];
    if (tab === 'dashboard') {
      opts.push({ label: 'Export PDF', path: '/reports/dashboard/export', params: { ...base, format: 'pdf' }, file: `dashboard-${range.from}.pdf`, mime: 'application/pdf' });
      opts.push({ label: 'Export Excel', path: '/reports/dashboard/export', params: { ...base, format: 'excel' }, file: `dashboard-${range.from}.csv`, mime: 'text/csv' });
    }
    if (tab === 'margin') {
      opts.push({ label: 'Export PDF', path: '/reports/margin/export', params: { ...base, format: 'pdf' }, file: 'margin.pdf', mime: 'application/pdf' });
      opts.push({ label: 'Export Excel', path: '/reports/margin/export', params: { ...base, format: 'excel' }, file: 'margin.csv', mime: 'text/csv' });
    }
    if (tab === 'profit') {
      opts.push({ label: 'Export PDF', path: '/reports/profit/export', params: { ...base, period, format: 'pdf' }, file: 'profit.pdf', mime: 'application/pdf' });
      opts.push({ label: 'Export Excel', path: '/reports/profit/export', params: { ...base, period, format: 'excel' }, file: 'profit.csv', mime: 'text/csv' });
    }
    if (tab === 'rental_delivery') {
      opts.push({ label: 'Export PDF', path: '/reports/rental/delivery/export', params: { ...base, location_id: locationParam, format: 'pdf' }, file: 'rental-delivery.pdf', mime: 'application/pdf' });
      opts.push({ label: 'Export Excel', path: '/reports/rental/delivery/export', params: { ...base, location_id: locationParam, format: 'excel' }, file: 'rental-delivery.csv', mime: 'text/csv' });
    }
    if (tab === 'rental_income') {
      opts.push({ label: 'Export PDF', path: '/reports/rental/income/export', params: { ...base, location_id: locationParam, period, format: 'pdf' }, file: 'rental-income.pdf', mime: 'application/pdf' });
      opts.push({ label: 'Export Excel', path: '/reports/rental/income/export', params: { ...base, location_id: locationParam, period, format: 'excel' }, file: 'rental-income.csv', mime: 'text/csv' });
    }
    if (tab === 'rental_current') {
      opts.push({ label: 'Export PDF', path: '/reports/rental/current/export', params: { ...companyParams, location_id: locationParam, format: 'pdf' }, file: 'rental-current.pdf', mime: 'application/pdf' });
      opts.push({ label: 'Export Excel', path: '/reports/rental/current/export', params: { ...companyParams, location_id: locationParam, format: 'excel' }, file: 'rental-current.csv', mime: 'text/csv' });
    }
    if (tab === 'rental_customer' && customerId) {
      opts.push({ label: 'Export PDF', path: `/reports/rental/customer/${customerId}/export`, params: { ...base, location_id: locationParam, format: 'pdf' }, file: 'rental-customer.pdf', mime: 'application/pdf' });
      opts.push({ label: 'Export Excel', path: `/reports/rental/customer/${customerId}/export`, params: { ...base, location_id: locationParam, format: 'excel' }, file: 'rental-customer.csv', mime: 'text/csv' });
    }
    return opts;
  }, [tab, range, companyParams, period, locationParam, customerId]);

  const modeRows = useMemo(() => Object.entries(dashQ.data?.sales?.by_mode ?? {}), [dashQ.data]);
  const marginRows = useMemo(() => {
    const rows = [...(marginQ.data?.rows ?? [])];
    rows.sort((a, b) => (b[sortKey] ?? 0) - (a[sortKey] ?? 0));
    return rows;
  }, [marginQ.data, sortKey]);

  async function exportFile(path, params, filename, mime) {
    try {
      await downloadWithParams(path, params, filename, mime);
      toast.success('Export downloaded.');
    } catch {
      toast.error('Export failed.');
    }
  }

  const locations = formData?.locations ?? [];
  const customers = formData?.customers ?? [];
  const suppliers = formData?.suppliers ?? [];
  const activeMeta = tabMeta(tab);

  const ActiveIcon = activeMeta.icon;

  const branchFilter = (show) => show && filterCompanyId !== 'all' && locations.length > 0 ? (
    <select value={locationId} onChange={(e) => setLocationId(e.target.value)} className={selectCls}>
      <option value="">All branches</option>
      {locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
    </select>
  ) : null;

  const subtitle = `Business summary and insights for ${isSuperAdmin ? viewingCompany : activeCompany?.name}${user?.name ? ` · ${user.name}` : ''}`;

  return (
    <div className="space-y-4 p-4 sm:p-6">
      <ReportsToolbar
        subtitle={subtitle}
        isSuperAdmin={isSuperAdmin}
        companyFilterValue={filterCompanyId}
        onCompanyChange={setFilterCompanyId}
        activeCompanyName={activeCompany?.name}
        range={range}
        onRangeChange={setRange}
        hideDateRange={hideDateRange}
        showCustomDates={showCustomDates}
        onToggleCustomDates={setShowCustomDates}
        exportOptions={exportOptions}
        onExport={(opt) => exportFile(opt.path, opt.params, opt.file, opt.mime)}
        extraFilters={(
          <>
            {branchFilter(tab === 'sales_analytics' || tab === 'leaderboard' || isRentalTab)}
            {tab === 'leaderboard' && (
              <select value={leaderboardPeriod} onChange={(e) => setLeaderboardPeriod(e.target.value)} className={selectCls}>
                <option value="month">Monthly</option>
                <option value="year">Financial year</option>
              </select>
            )}
            {tab === 'inventory_movement' && (
              <>
                <select value={movementDays} onChange={(e) => setMovementDays(Number(e.target.value))} className={selectCls}>
                  <option value={7}>7 days</option>
                  <option value={30}>30 days</option>
                  <option value={90}>90 days</option>
                  <option value={180}>180 days</option>
                </select>
                <select value={movementClass} onChange={(e) => setMovementClass(e.target.value)} className={selectCls}>
                  <option value="all">All items</option>
                  <option value="fast">Fast moving</option>
                  <option value="slow">Slow moving</option>
                  <option value="dead">Dead stock</option>
                </select>
              </>
            )}
            {tab === 'debtor_ledger' && (
              <select value={customerId} onChange={(e) => setCustomerId(e.target.value)} className={selectCls}>
                <option value="">Select customer</option>
                {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            )}
            {tab === 'creditor_ledger' && (
              <select value={supplierId} onChange={(e) => setSupplierId(e.target.value)} className={selectCls}>
                <option value="">Select supplier</option>
                {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            )}
            {tab === 'rental_customer' && (
              <select value={customerId} onChange={(e) => setCustomerId(e.target.value)} className={selectCls}>
                <option value="">Select customer</option>
                {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            )}
            {filterCompanyId === 'all' && ['debtor_ledger', 'creditor_ledger', 'rental_customer'].includes(tab) && (
              <span className="text-xs text-warning">Select a company for party lists</span>
            )}
          </>
        )}
      />

      <ReportNavGrid
        tabs={visibleTabs}
        activeTab={tab}
        onSelect={setTab}
        search={reportSearch}
        onSearchChange={setReportSearch}
      />

      <div className="flex items-center gap-2 text-sm text-muted">
        <ActiveIcon className="size-4 text-leaf" strokeWidth={1.5} />
        <span className="font-medium text-ink">{activeMeta.label}</span>
      </div>

      {tab === 'dashboard' && (
        <>
          {dashQ.isLoading ? <div className="flex justify-center py-20"><Spinner className="size-6" /></div>
            : dashQ.isError || !dashQ.data ? <ReportEmptyState title="Couldn't load dashboard" description="Check your connection or try another company filter." onChangeFilters={() => setShowCustomDates(true)} />
            : (
              <>
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                  <StatCard label="Sales" value={formatCurrency(dashQ.data.sales.total)} sub={`${dashQ.data.sales.count} invoices`} />
                  <StatCard label="Purchases" value={formatCurrency(dashQ.data.purchases.total)} sub={`${dashQ.data.purchases.count} GRNs`} />
                  <StatCard label="Receivables" value={formatCurrency(dashQ.data.receivables)} sub="owed by customers" />
                  <StatCard label="Payables" value={formatCurrency(dashQ.data.payables)} sub="owed to suppliers" />
                  <StatCard label="Stock value" value={formatCurrency(dashQ.data.inventory.stock_value)} sub={`${dashQ.data.inventory.skus} SKUs`} />
                </div>
                <Card className="p-5">
                  <div className="mb-3 flex items-center justify-between">
                    <h2 className="text-sm font-semibold">Sales trend</h2>
                    <span className="text-xs text-muted">{formatDate(range.from)} – {formatDate(range.to)}</span>
                  </div>
                  <TrendChart data={dashQ.data.sales.trend} />
                </Card>
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                  <Card className="overflow-hidden">
                    <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">Top products</div>
                    {dashQ.data.top_products.length === 0 ? <div className="px-4 py-10 text-center text-sm text-muted">No sales yet.</div>
                      : (
                        <div className="overflow-x-auto">
                          <table className="w-full text-sm">
                            <thead><tr className="border-b border-line text-left text-faint"><th className="microlabel px-4 py-2 font-semibold">Product</th><th className="microlabel px-4 py-2 text-right font-semibold">Qty</th><th className="microlabel px-4 py-2 text-right font-semibold">Revenue</th></tr></thead>
                            <tbody>{dashQ.data.top_products.map((p) => (
                              <tr key={p.name} className="border-b border-line/60 last:border-0"><td className="px-4 py-2 font-medium">{p.name}</td><td className="tnum px-4 py-2 text-right text-muted">{p.qty}</td><td className="tnum px-4 py-2 text-right font-medium">{formatCurrency(p.revenue)}</td></tr>
                            ))}</tbody>
                          </table>
                        </div>
                      )}
                  </Card>
                  <Card className="overflow-hidden">
                    <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">Top customers</div>
                    {dashQ.data.top_customers.length === 0 ? <div className="px-4 py-10 text-center text-sm text-muted">No sales yet.</div>
                      : (
                        <div className="overflow-x-auto">
                          <table className="w-full text-sm">
                            <thead><tr className="border-b border-line text-left text-faint"><th className="microlabel px-4 py-2 font-semibold">Customer</th><th className="microlabel px-4 py-2 text-right font-semibold">Revenue</th></tr></thead>
                            <tbody>{dashQ.data.top_customers.map((c) => (
                              <tr key={c.name} className="border-b border-line/60 last:border-0"><td className="px-4 py-2 font-medium">{c.name}</td><td className="tnum px-4 py-2 text-right font-medium">{formatCurrency(c.revenue)}</td></tr>
                            ))}</tbody>
                          </table>
                        </div>
                      )}
                  </Card>
                </div>
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-4">
                  <Card className="p-5">
                    <h2 className="mb-3 text-sm font-semibold">Sales by payment mode</h2>
                    {modeRows.length === 0 ? <p className="text-sm text-muted">No sales.</p>
                      : (
                        <div className="space-y-1.5">{modeRows.map(([mode, total]) => (
                          <div key={mode} className="flex items-center justify-between text-sm"><Badge tone="warning">{mode}</Badge><span className="tnum font-medium">{formatCurrency(total)}</span></div>
                        ))}
                        </div>
                      )}
                  </Card>
                  <Card className="p-5">
                    <h2 className="mb-3 text-sm font-semibold">Production</h2>
                    <div className="flex items-baseline justify-between text-sm"><span className="text-muted">Completed runs</span><span className="tnum font-medium">{dashQ.data.production.completed}</span></div>
                    <div className="mt-1 flex items-baseline justify-between text-sm"><span className="text-muted">Input value</span><span className="tnum font-medium">{formatCurrency(dashQ.data.production.output_value)}</span></div>
                  </Card>
                  <Card className="p-5">
                    <h2 className="mb-3 text-sm font-semibold">Rentals</h2>
                    <div className="flex items-baseline justify-between text-sm"><span className="text-muted">Active rentals</span><span className="tnum font-medium">{dashQ.data.rentals.active}</span></div>
                    <div className="mt-1 flex items-baseline justify-between text-sm"><span className="text-muted">Return overdue</span><span className="tnum font-medium text-danger">{dashQ.data.rentals.overdue_returns ?? 0}</span></div>
                    <div className="mt-1 flex items-baseline justify-between text-sm"><span className="text-muted">Payment overdue</span><span className="tnum font-medium text-danger">{dashQ.data.rentals.payment_overdue ?? 0}</span></div>
                    <div className="mt-1 flex items-baseline justify-between text-sm"><span className="text-muted">Invoiced in range</span><span className="tnum font-medium">{formatCurrency(dashQ.data.rentals.invoiced)}</span></div>
                  </Card>
                  <Card className="p-5">
                    <h2 className="mb-3 text-sm font-semibold">Transfers</h2>
                    <div className="flex items-baseline justify-between text-sm"><span className="text-muted">In transit</span><span className="tnum font-medium">{dashQ.data.transfers?.in_transit ?? 0}</span></div>
                    <div className="mt-1 flex items-baseline justify-between text-sm"><span className="text-muted">Pending approval</span><span className="tnum font-medium">{dashQ.data.transfers?.pending_approval ?? 0}</span></div>
                    <div className="mt-1 flex items-baseline justify-between text-sm"><span className="text-muted">Received in range</span><span className="tnum font-medium">{dashQ.data.transfers?.received_in_range ?? 0}</span></div>
                  </Card>
                </div>
              </>
            )}
        </>
      )}

      {tab === 'margin' && (
        <>
          <div className="flex flex-wrap items-center gap-2">
            <select value={sortKey} onChange={(e) => setSortKey(e.target.value)} className={selectCls}>
              <option value="margin_pct">Sort by margin %</option>
              <option value="margin">Sort by margin ₹</option>
              <option value="revenue">Sort by revenue</option>
            </select>
          </div>
          <Card className="overflow-hidden">
            {marginQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
              : marginRows.length === 0 ? (
                <ReportEmptyState title="No margin data" description="No product margin in the selected date range." onChangeFilters={() => setShowCustomDates(true)} />
              )
              : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead><tr className="border-b border-line text-left text-faint">
                      <th className="microlabel px-4 py-2.5 font-semibold">Product</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Revenue</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">COGS</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Margin</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Margin %</th>
                    </tr></thead>
                    <tbody>
                      {marginRows.map((r) => (
                        <tr key={r.id ?? r.name} className="border-b border-line/60 last:border-0">
                          <td className="px-4 py-2.5 font-medium">{r.name}</td>
                          <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.revenue)}</td>
                          <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency(r.cogs)}</td>
                          <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(r.margin)}</td>
                          <td className="tnum px-4 py-2.5 text-right">{r.margin_pct}%</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
          </Card>
        </>
      )}

      {tab === 'profit' && (
        <>
          <div className="flex flex-wrap items-center gap-2">
            <select value={period} onChange={(e) => setPeriod(e.target.value)} className={selectCls}>
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
              <option value="yearly">Yearly</option>
            </select>
          </div>
          {profitQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : !profitQ.data ? <Card className="px-4 py-16 text-center text-sm text-muted">Couldn't load profit report.</Card>
            : (
              <>
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                  <StatCard label="Sales" value={formatCurrency(profitQ.data.aggregate.sales)} />
                  <StatCard label="COGS" value={formatCurrency(profitQ.data.aggregate.cogs)} />
                  <StatCard label="Expenses" value={formatCurrency(profitQ.data.aggregate.expenses)} sub={`${profitQ.data.aggregate.days} days`} />
                  <StatCard label="Approx. profit" value={formatCurrency(profitQ.data.aggregate.profit)} />
                </div>
                <Card className="overflow-hidden">
                  <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">Company breakdown</div>
                  {(profitQ.data.by_branch?.length ?? 0) === 0 ? <div className="px-4 py-12 text-center text-sm text-muted">No branch sales in this range.</div>
                    : (
                      <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                          <thead><tr className="border-b border-line text-left text-faint">
                            <th className="microlabel px-4 py-2.5 font-semibold">Company</th>
                            <th className="microlabel px-4 py-2.5 text-right font-semibold">Sales</th>
                            <th className="microlabel px-4 py-2.5 text-right font-semibold">COGS</th>
                            <th className="microlabel px-4 py-2.5 text-right font-semibold">Expenses</th>
                            <th className="microlabel px-4 py-2.5 text-right font-semibold">Profit</th>
                          </tr></thead>
                          <tbody>
                            {profitQ.data.by_branch.map((b) => (
                              <tr key={b.location_id} className="border-b border-line/60 last:border-0">
                                <td className="px-4 py-2.5 font-medium">{b.location_name}</td>
                                <td className="tnum px-4 py-2.5 text-right">{formatCurrency(b.sales)}</td>
                                <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency(b.cogs)}</td>
                                <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency(b.expenses)}</td>
                                <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(b.profit)}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    )}
                </Card>
              </>
            )}
        </>
      )}

      {tab === 'price_levels' && (
        <>
          {priceLevelQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : !priceLevelQ.data ? <Card className="px-4 py-16 text-center text-sm text-muted">Couldn't load price tier report.</Card>
            : (
              <>
                <StatCard label="Total revenue" value={formatCurrency(priceLevelQ.data.total)} />
                <Card className="overflow-hidden">
                  <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">Revenue by price tier</div>
                  {(priceLevelQ.data.rows?.length ?? 0) === 0 ? (
                    <div className="px-4 py-12 text-center text-sm text-muted">No confirmed sales in this range.</div>
                  ) : (
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm">
                        <thead><tr className="border-b border-line text-left text-faint">
                          <th className="microlabel px-4 py-2.5 font-semibold">Price tier</th>
                          <th className="microlabel px-4 py-2.5 text-right font-semibold">Sales</th>
                          <th className="microlabel px-4 py-2.5 text-right font-semibold">Qty</th>
                          <th className="microlabel px-4 py-2.5 text-right font-semibold">Revenue</th>
                        </tr></thead>
                        <tbody>
                          {priceLevelQ.data.rows.map((r) => (
                            <tr key={r.price_level} className="border-b border-line/60 last:border-0">
                              <td className="px-4 py-2.5 font-medium">{r.label}</td>
                              <td className="tnum px-4 py-2.5 text-right">{r.sale_count}</td>
                              <td className="tnum px-4 py-2.5 text-right">{r.qty}</td>
                              <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(r.revenue)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </Card>
              </>
            )}
        </>
      )}

      {tab === 'rental_delivery' && (
        <>
          <Card className="overflow-hidden">
            {rentalDeliveryQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
              : (rentalDeliveryQ.data?.data?.length ?? 0) === 0 ? (
                <ReportEmptyState title="No rental deliveries" description="No deliveries in the selected date range." onChangeFilters={() => setShowCustomDates(true)} />
              )
              : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead><tr className="border-b border-line text-left text-faint">
                      <th className="microlabel px-4 py-2.5 font-semibold">Rental</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Customer</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Items</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Delivery</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Deposit</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                    </tr></thead>
                    <tbody>
                      {rentalDeliveryQ.data.data.map((r) => (
                        <tr key={r.id} className="border-b border-line/60 last:border-0">
                          <td className="px-4 py-2.5 font-medium">{r.rental_no}</td>
                          <td className="px-4 py-2.5">{r.customer_name}</td>
                          <td className="px-4 py-2.5 text-muted">{r.items_summary}</td>
                          <td className="px-4 py-2.5 text-muted">{formatDate(r.delivery_date)}</td>
                          <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.deposit)}</td>
                          <td className="px-4 py-2.5"><Badge tone={statusTone[r.status] ?? 'default'}>{r.status}</Badge></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
          </Card>
        </>
      )}

      {tab === 'rental_income' && (
        <>
          <div className="flex flex-wrap items-center gap-2">
            <select value={period} onChange={(e) => setPeriod(e.target.value)} className={selectCls}>
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>
          {rentalIncomeQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : !rentalIncomeQ.data ? <Card className="px-4 py-16 text-center text-sm text-muted">Couldn't load rental income.</Card>
            : (
              <>
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
                  <StatCard label="Invoiced total" value={formatCurrency(rentalIncomeQ.data.total)} sub={`${rentalIncomeQ.data.count} invoices`} />
                </div>
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                  <Card className="overflow-hidden">
                    <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">By {period.replace('ly', '')}</div>
                    {(rentalIncomeQ.data.by_period?.length ?? 0) === 0 ? <div className="px-4 py-10 text-center text-sm text-muted">No invoices.</div>
                      : (
                        <div className="overflow-x-auto">
                          <table className="w-full text-sm">
                            <thead><tr className="border-b border-line text-left text-faint">
                              <th className="microlabel px-4 py-2 font-semibold">Period</th>
                              <th className="microlabel px-4 py-2 text-right font-semibold">Count</th>
                              <th className="microlabel px-4 py-2 text-right font-semibold">Amount</th>
                            </tr></thead>
                            <tbody>{rentalIncomeQ.data.by_period.map((p) => (
                              <tr key={p.period} className="border-b border-line/60 last:border-0">
                                <td className="px-4 py-2 font-medium">{p.period}</td>
                                <td className="tnum px-4 py-2 text-right text-muted">{p.count}</td>
                                <td className="tnum px-4 py-2 text-right font-medium">{formatCurrency(p.amount)}</td>
                              </tr>
                            ))}</tbody>
                          </table>
                        </div>
                      )}
                  </Card>
                  <Card className="overflow-hidden">
                    <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">By branch</div>
                    {(rentalIncomeQ.data.by_branch?.length ?? 0) === 0 ? <div className="px-4 py-10 text-center text-sm text-muted">No invoices.</div>
                      : (
                        <div className="overflow-x-auto">
                          <table className="w-full text-sm">
                            <thead><tr className="border-b border-line text-left text-faint">
                              <th className="microlabel px-4 py-2 font-semibold">Branch</th>
                              <th className="microlabel px-4 py-2 text-right font-semibold">Count</th>
                              <th className="microlabel px-4 py-2 text-right font-semibold">Amount</th>
                            </tr></thead>
                            <tbody>{rentalIncomeQ.data.by_branch.map((b) => (
                              <tr key={b.location_id ?? b.location_name} className="border-b border-line/60 last:border-0">
                                <td className="px-4 py-2 font-medium">{b.location_name}</td>
                                <td className="tnum px-4 py-2 text-right text-muted">{b.count}</td>
                                <td className="tnum px-4 py-2 text-right font-medium">{formatCurrency(b.amount)}</td>
                              </tr>
                            ))}</tbody>
                          </table>
                        </div>
                      )}
                  </Card>
                </div>
                <Card className="overflow-hidden">
                  <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">Invoice detail</div>
                  {(rentalIncomeQ.data.rows?.length ?? 0) === 0 ? <div className="px-4 py-12 text-center text-sm text-muted">No rental invoices in this range.</div>
                    : (
                      <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                          <thead><tr className="border-b border-line text-left text-faint">
                            <th className="microlabel px-4 py-2.5 font-semibold">Invoice</th>
                            <th className="microlabel px-4 py-2.5 font-semibold">Rental</th>
                            <th className="microlabel px-4 py-2.5 font-semibold">Customer</th>
                            <th className="microlabel px-4 py-2.5 font-semibold">Period</th>
                            <th className="microlabel px-4 py-2.5 text-right font-semibold">Amount</th>
                            <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                          </tr></thead>
                          <tbody>
                            {rentalIncomeQ.data.rows.map((r) => (
                              <tr key={r.invoice_no} className="border-b border-line/60 last:border-0">
                                <td className="px-4 py-2.5 font-medium">{r.invoice_no}</td>
                                <td className="px-4 py-2.5 text-muted">{r.rental_no}</td>
                                <td className="px-4 py-2.5">{r.customer_name}</td>
                                <td className="px-4 py-2.5 text-muted">{formatDate(r.period_from)} – {formatDate(r.period_to)}</td>
                                <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(r.amount)}</td>
                                <td className="px-4 py-2.5"><Badge tone={r.status === 'paid' ? 'active' : 'warning'}>{r.status}</Badge></td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    )}
                </Card>
              </>
            )}
        </>
      )}

      {tab === 'rental_current' && (
        <Card className="overflow-hidden">
          {rentalCurrentQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : (rentalCurrentQ.data?.data?.length ?? 0) === 0 ? (
              <ReportEmptyState title="Nothing on rent" description="No items are currently out on rental." />
            )
              : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead><tr className="border-b border-line text-left text-faint">
                      <th className="microlabel px-4 py-2.5 font-semibold">Customer</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Item</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Qty</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Delivery</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Days</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                    </tr></thead>
                    <tbody>
                      {rentalCurrentQ.data.data.map((r, i) => (
                        <tr key={`${r.rental_id}-${r.product_id}-${i}`} className="border-b border-line/60 last:border-0">
                          <td className="px-4 py-2.5 font-medium">{r.customer_name}<div className="text-xs text-muted">{r.rental_no}</div></td>
                          <td className="px-4 py-2.5">{r.product_name}</td>
                          <td className="tnum px-4 py-2.5 text-right">{r.qty}</td>
                          <td className="px-4 py-2.5 text-muted">{formatDate(r.delivery_date)}</td>
                          <td className="tnum px-4 py-2.5 text-right">{r.days_elapsed}</td>
                          <td className="px-4 py-2.5"><Badge tone={statusTone[r.status] ?? 'default'}>{r.status}</Badge></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
        </Card>
      )}

      {tab === 'rental_customer' && (
        <Card className="overflow-hidden">
          {!customerId ? (
            <ReportEmptyState title="Select a customer" description="Choose a customer from the filter above to view rental history." />
          )
              : rentalCustomerQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
              : (rentalCustomerQ.data?.data?.length ?? 0) === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No rentals for this customer.</div>
              : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead><tr className="border-b border-line text-left text-faint">
                      <th className="microlabel px-4 py-2.5 font-semibold">Rental</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Dates</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Items</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Deposit</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Charges</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Damage / Missing</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Refund</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                    </tr></thead>
                    <tbody>
                      {rentalCustomerQ.data.data.map((r) => (
                        <tr key={r.id} className="border-b border-line/60 last:border-0">
                          <td className="px-4 py-2.5 font-medium">{r.rental_no}</td>
                          <td className="px-4 py-2.5 text-muted text-xs">
                            {formatDate(r.start_date)}
                            {r.delivery_date ? ` · del ${formatDate(r.delivery_date)}` : ''}
                            {r.return_date ? ` · ret ${formatDate(r.return_date)}` : ''}
                          </td>
                          <td className="px-4 py-2.5 text-muted">{r.items_summary}</td>
                          <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.deposit)}</td>
                          <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.rental_charge || r.invoiced_total)}</td>
                          <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency((r.damage_charge || 0) + (r.missing_charge || 0))}</td>
                          <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.refund_amount)}</td>
                          <td className="px-4 py-2.5"><Badge tone={statusTone[r.status] ?? 'default'}>{r.status}</Badge></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
        </Card>
      )}

      {tab === 'production_summary' && (
        productionSummaryQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
          : !productionSummaryQ.data?.data ? <Card className="px-4 py-16 text-center text-sm text-muted">Could not load production summary.</Card>
          : (
            <>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                <StatCard label="Completed runs" value={productionSummaryQ.data.data.summary?.completed ?? 0} />
                <StatCard label="Output qty" value={productionSummaryQ.data.data.summary?.output_qty ?? 0} />
                <StatCard label="Total cost" value={formatCurrency(productionSummaryQ.data.data.summary?.total_cost ?? 0)} />
                <StatCard label="Avg unit cost" value={formatCurrency(productionSummaryQ.data.data.summary?.avg_unit_cost ?? 0)} />
              </div>
              <Card className="mt-4 overflow-hidden">
                {(productionSummaryQ.data.data.data?.length ?? 0) === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No completed production in this range.</div>
                : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead><tr className="border-b border-line text-left text-faint">
                      <th className="microlabel px-4 py-2.5 font-semibold">Order</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Product</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Supervisor</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Qty</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Cost</th>
                    </tr></thead>
                    <tbody>
                      {productionSummaryQ.data.data.data.map((r) => (
                        <tr key={r.id} className="border-b border-line/60 last:border-0">
                          <td className="px-4 py-2.5 font-medium">{r.order_no}</td>
                          <td className="px-4 py-2.5 text-muted">{r.output_product}</td>
                          <td className="px-4 py-2.5 text-muted">{r.supervisor || '—'}</td>
                          <td className="tnum px-4 py-2.5 text-right">{r.output_quantity}</td>
                          <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.total_input_cost)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                )}
              </Card>
            </>
          )
      )}

      {tab === 'production_by_product' && (
        <Card className="overflow-hidden">
          {productionByProductQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : (productionByProductQ.data?.rows?.length ?? 0) === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No production by product in this range.</div>
            : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">Product</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Runs</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Output qty</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Total cost</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Avg unit</th>
                  </tr></thead>
                  <tbody>
                    {productionByProductQ.data.rows.map((r) => (
                      <tr key={r.product_id} className="border-b border-line/60 last:border-0">
                        <td className="px-4 py-2.5 font-medium">{r.product_name}<div className="text-xs text-muted">{r.sku}</div></td>
                        <td className="tnum px-4 py-2.5 text-right">{r.run_count}</td>
                        <td className="tnum px-4 py-2.5 text-right">{r.output_qty}</td>
                        <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.total_cost)}</td>
                        <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.avg_unit_cost)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
        </Card>
      )}

      {tab === 'production_by_supervisor' && (
        productionBySupervisorQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
          : (productionBySupervisorQ.data?.rows?.length ?? 0) === 0 ? (
            <ReportEmptyState
              title="No production found"
              description="There is no production by supervisor in the selected range."
              onChangeFilters={() => setShowCustomDates(true)}
            />
          ) : (
            <Card className="overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">Supervisor</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Runs</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Output qty</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Total cost</th>
                  </tr></thead>
                  <tbody>
                    {productionBySupervisorQ.data.rows.map((r, i) => (
                      <tr key={r.supervisor_id ?? `u-${i}`} className="border-b border-line/60 last:border-0">
                        <td className="px-4 py-2.5 font-medium">{r.supervisor_name}</td>
                        <td className="tnum px-4 py-2.5 text-right">{r.run_count}</td>
                        <td className="tnum px-4 py-2.5 text-right">{r.output_qty}</td>
                        <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.total_cost)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          )
      )}

      {tab === 'production_batches' && (
        <Card className="overflow-hidden">
          {productionBatchesQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : (productionBatchesQ.data?.data?.length ?? 0) === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No production batches in this range.</div>
            : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">Batch / barcode</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Product</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Order</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Supervisor</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Qty</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Unit cost</th>
                  </tr></thead>
                  <tbody>
                    {productionBatchesQ.data.data.map((r) => (
                      <tr key={r.id} className="border-b border-line/60 last:border-0">
                        <td className="px-4 py-2.5"><div className="font-medium">{r.batch_no}</div><div className="text-xs text-muted">{r.barcode}</div></td>
                        <td className="px-4 py-2.5 text-muted">{r.product_name}</td>
                        <td className="px-4 py-2.5 text-muted">{r.order_no}</td>
                        <td className="px-4 py-2.5 text-muted">{r.supervisor || '—'}</td>
                        <td className="tnum px-4 py-2.5 text-right">{r.qty}</td>
                        <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.cost_price)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
        </Card>
      )}

      {tab === 'transfer_summary' && (
        transferSummaryQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
          : !transferSummaryQ.data?.data ? <Card className="px-4 py-16 text-center text-sm text-muted">Could not load transfer summary.</Card>
          : (
            <>
              <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <StatCard label="Total" value={transferSummaryQ.data.data.summary?.total ?? 0} />
                <StatCard label="Received" value={transferSummaryQ.data.data.summary?.received ?? 0} />
                <StatCard label="In transit" value={transferSummaryQ.data.data.summary?.in_transit ?? 0} />
                <StatCard label="Requested" value={transferSummaryQ.data.data.summary?.requested ?? 0} />
                <StatCard label="Inter-company" value={transferSummaryQ.data.data.summary?.inter_company ?? 0} />
                <StatCard label="Location moves" value={transferSummaryQ.data.data.summary?.intra_company ?? 0} />
              </div>
              <Card className="mt-4 overflow-hidden">
                {(transferSummaryQ.data.data.data?.length ?? 0) === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No transfers in this range.</div>
                : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead><tr className="border-b border-line text-left text-faint">
                      <th className="microlabel px-4 py-2.5 font-semibold">No.</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Route</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Type</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Lines</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                    </tr></thead>
                    <tbody>
                      {transferSummaryQ.data.data.data.map((r) => (
                        <tr key={r.id} className="border-b border-line/60 last:border-0">
                          <td className="px-4 py-2.5 font-medium">{r.transfer_no}</td>
                          <td className="px-4 py-2.5 text-muted">{formatDate(r.transfer_date)}</td>
                          <td className="px-4 py-2.5 text-muted">{r.route}</td>
                          <td className="px-4 py-2.5"><Badge tone="info">{r.transfer_type === 'intra_company' ? 'Location' : 'Company'}</Badge></td>
                          <td className="tnum px-4 py-2.5 text-right">{r.items_count}</td>
                          <td className="px-4 py-2.5"><Badge tone={statusTone[r.status] ?? 'default'}>{r.status}</Badge></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                )}
              </Card>
            </>
          )
      )}

      {tab === 'transfer_in_transit' && (
        <Card className="overflow-hidden">
          {transferInTransitQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : (transferInTransitQ.data?.data?.length ?? 0) === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No transfers currently in transit.</div>
            : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">No.</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Route</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Items</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Qty</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Days</th>
                  </tr></thead>
                  <tbody>
                    {transferInTransitQ.data.data.map((r) => (
                      <tr key={r.id} className="border-b border-line/60 last:border-0">
                        <td className="px-4 py-2.5 font-medium">{r.transfer_no}</td>
                        <td className="px-4 py-2.5 text-muted">{r.route}</td>
                        <td className="px-4 py-2.5 text-muted">{r.items_summary}</td>
                        <td className="tnum px-4 py-2.5 text-right">{r.qty}</td>
                        <td className="tnum px-4 py-2.5 text-right">{r.days_in_transit}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
        </Card>
      )}

      {tab === 'inventory_movement' && (
        <>
          {movementQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : (
              <>
                <div className="grid grid-cols-3 gap-4">
                  <StatCard label="Fast moving" value={movementQ.data?.summary?.fast ?? 0} tone="active" />
                  <StatCard label="Slow moving" value={movementQ.data?.summary?.slow ?? 0} tone="warning" />
                  <StatCard label="Dead stock" value={movementQ.data?.summary?.dead ?? 0} tone="blocked" />
                </div>
                <Card className="mt-4 overflow-hidden">
                  {movementRows.length === 0 ? (
                    <ReportEmptyState title="No items found" description={`No ${movementClass === 'all' ? '' : movementClass + ' '}stock in the last ${movementDays} days.`} />
                  ) : (
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm">
                        <thead><tr className="border-b border-line text-left text-faint">
                          <th className="microlabel px-4 py-2.5 font-semibold">Product</th>
                          <th className="microlabel px-4 py-2.5 text-right font-semibold">Stock</th>
                          <th className="microlabel px-4 py-2.5 text-right font-semibold">Qty sold</th>
                          <th className="microlabel px-4 py-2.5 font-semibold">Last sale</th>
                          <th className="microlabel px-4 py-2.5 font-semibold">Class</th>
                        </tr></thead>
                        <tbody>
                          {movementRows.slice(0, 100).map((r) => (
                            <tr key={r.id} className="border-b border-line/60 last:border-0">
                              <td className="px-4 py-2.5"><div className="font-medium">{r.name}</div><div className="text-xs text-muted">{r.sku}</div></td>
                              <td className="tnum px-4 py-2.5 text-right">{r.stock}</td>
                              <td className="tnum px-4 py-2.5 text-right">{r.out_qty}</td>
                              <td className="px-4 py-2.5 text-muted">{r.last_out ? formatDate(r.last_out) : '—'}</td>
                              <td className="px-4 py-2.5"><Badge tone={r.class === 'fast' ? 'active' : r.class === 'slow' ? 'warning' : 'blocked'}>{r.class}</Badge></td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </Card>
              </>
            )}
        </>
      )}

      {tab === 'sales_analytics' && (
        <>
          {(salesMonthQ.isLoading || salesYoyQ.isLoading) ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : (
              <>
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                  <Card className="p-5">
                    <h2 className="mb-3 text-sm font-semibold">Current month vs last month</h2>
                    {salesMonthQ.data && (
                      <>
                        <div className="grid grid-cols-2 gap-3 text-sm">
                          <StatCard label="Current net" value={formatCurrency(salesMonthQ.data.current?.net_sales)} sub={`${salesMonthQ.data.current?.invoice_count ?? 0} invoices`} />
                          <StatCard label="Previous net" value={formatCurrency(salesMonthQ.data.previous?.net_sales)} sub={`${salesMonthQ.data.previous?.invoice_count ?? 0} invoices`} />
                        </div>
                        <p className="mt-3 text-sm">
                          Growth: <span className="font-medium">{formatCurrency(salesMonthQ.data.difference?.net_sales)}</span>
                          {' '}({salesMonthQ.data.difference?.growth_pct ?? 0}%)
                        </p>
                      </>
                    )}
                  </Card>
                  <Card className="p-5">
                    <h2 className="mb-3 text-sm font-semibold">Year-on-year ({salesYoyQ.data?.current_label})</h2>
                    {salesYoyQ.data && (
                      <>
                        <div className="grid grid-cols-2 gap-3 text-sm">
                          <StatCard label={salesYoyQ.data.current_label} value={formatCurrency(salesYoyQ.data.current?.net_sales)} />
                          <StatCard label={salesYoyQ.data.previous_label} value={formatCurrency(salesYoyQ.data.previous?.net_sales)} />
                        </div>
                        <p className="mt-3 text-sm">YoY growth: {salesYoyQ.data.difference?.growth_pct ?? 0}%</p>
                      </>
                    )}
                  </Card>
                </div>
                <Card className="mt-4 p-5">
                  <h2 className="mb-3 text-sm font-semibold">Year-to-date ({salesYtdQ.data?.as_of})</h2>
                  {salesYtdQ.data && (
                    <>
                      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <StatCard label="YTD net" value={formatCurrency(salesYtdQ.data.current?.net_sales)} />
                        <StatCard label="Prior YTD" value={formatCurrency(salesYtdQ.data.previous?.net_sales)} />
                        <StatCard label="Returns" value={formatCurrency(salesYtdQ.data.current?.returns)} />
                        <StatCard label="GST" value={formatCurrency(salesYtdQ.data.current?.tax)} />
                      </div>
                    </>
                  )}
                </Card>
                <Card className="mt-4 overflow-hidden">
                  <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">12-month trend</div>
                  {(salesTrendQ.data?.months?.length ?? 0) === 0 ? <div className="px-4 py-12 text-center text-sm text-muted">No data.</div>
                    : (
                      <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                          <thead><tr className="border-b border-line text-left text-faint">
                            <th className="microlabel px-4 py-2 font-semibold">Month</th>
                            <th className="microlabel px-4 py-2 text-right font-semibold">Net sales</th>
                            <th className="microlabel px-4 py-2 text-right font-semibold">Returns</th>
                            <th className="microlabel px-4 py-2 text-right font-semibold">Invoices</th>
                            <th className="microlabel px-4 py-2 text-right font-semibold">MoM %</th>
                          </tr></thead>
                          <tbody>
                            {salesTrendQ.data.months.map((m) => (
                              <tr key={m.month} className="border-b border-line/60 last:border-0">
                                <td className="px-4 py-2 font-medium">{m.label}</td>
                                <td className="tnum px-4 py-2 text-right">{formatCurrency(m.net_sales)}</td>
                                <td className="tnum px-4 py-2 text-right text-muted">{formatCurrency(m.returns)}</td>
                                <td className="tnum px-4 py-2 text-right">{m.invoice_count}</td>
                                <td className="tnum px-4 py-2 text-right">{m.growth_pct != null ? `${m.growth_pct}%` : '—'}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    )}
                </Card>
              </>
            )}
        </>
      )}

      {tab === 'leaderboard' && (
        <Card className="overflow-hidden">
          {leaderboardQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : (leaderboardQ.data?.rankings?.length ?? 0) === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No sales in this period.</div>
            : (
              <>
                <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">{leaderboardQ.data.label} — ranked by net sales</div>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead><tr className="border-b border-line text-left text-faint">
                      <th className="microlabel px-4 py-2.5 font-semibold">Rank</th>
                      <th className="microlabel px-4 py-2.5 font-semibold">Employee</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Net sales</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Invoices</th>
                      {canCommissionReport && <th className="microlabel px-4 py-2.5 text-right font-semibold">Incentives</th>}
                    </tr></thead>
                    <tbody>
                      {leaderboardQ.data.rankings.map((r) => (
                        <tr key={r.user_id} className="border-b border-line/60 last:border-0">
                          <td className="px-4 py-2.5 font-medium">#{r.rank}</td>
                          <td className="px-4 py-2.5">{r.user_name}</td>
                          <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(r.net_sales)}</td>
                          <td className="tnum px-4 py-2.5 text-right text-muted">{r.invoices}</td>
                          {canCommissionReport && <td className="tnum px-4 py-2.5 text-right">{formatCurrency(r.incentives)}</td>}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}
        </Card>
      )}

      {tab === 'gst_reconciliation' && (
        <>
          {gstQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : !gstQ.data ? <Card className="px-4 py-16 text-center text-sm text-muted">Could not load GST reconciliation.</Card>
            : (
              <>
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                  <StatCard label="Output GST" value={formatCurrency(gstQ.data.output?.tax_total)} sub={`${gstQ.data.output?.invoice_count ?? 0} invoices`} />
                  <StatCard label="Input GST" value={formatCurrency(gstQ.data.input?.tax_total)} sub={`${gstQ.data.input?.bill_count ?? 0} purchases`} />
                  <StatCard label="Return GST" value={formatCurrency(gstQ.data.sales_returns?.tax)} />
                  <StatCard label="Net payable" value={formatCurrency(gstQ.data.net_gst_payable)} />
                </div>
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                  <Card className="p-5">
                    <h2 className="mb-2 text-sm font-semibold">Output tax split</h2>
                    <p className="text-sm text-muted">CGST {formatCurrency(gstQ.data.output?.cgst)} · SGST {formatCurrency(gstQ.data.output?.sgst)} · IGST {formatCurrency(gstQ.data.output?.igst)}</p>
                  </Card>
                  <Card className="p-5">
                    <h2 className="mb-2 text-sm font-semibold">Input tax split</h2>
                    <p className="text-sm text-muted">CGST {formatCurrency(gstQ.data.input?.cgst)} · SGST {formatCurrency(gstQ.data.input?.sgst)} · IGST {formatCurrency(gstQ.data.input?.igst)}</p>
                  </Card>
                </div>
              </>
            )}
        </>
      )}

      {tab === 'commission_report' && (
        <Card className="overflow-hidden">
          {commissionReportQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
            : (commissionReportQ.data?.staff?.length ?? 0) === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No commission in this range.</div>
            : (
              <>
                <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">
                  Total commission {formatCurrency(commissionReportQ.data.totals?.commission)} · Supervisor {formatCurrency(commissionReportQ.data.totals?.supervisor)}
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead><tr className="border-b border-line text-left text-faint">
                      <th className="microlabel px-4 py-2.5 font-semibold">Employee</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Net sales</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Tier</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Daily target</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Promo</th>
                      <th className="microlabel px-4 py-2.5 text-right font-semibold">Total</th>
                    </tr></thead>
                    <tbody>
                      {commissionReportQ.data.staff.map((s) => (
                        <tr key={s.user_id} className="border-b border-line/60 last:border-0">
                          <td className="px-4 py-2.5 font-medium">{s.user_name}</td>
                          <td className="tnum px-4 py-2.5 text-right">{formatCurrency(s.sales_net)}</td>
                          <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency(s.salesman_tier)}</td>
                          <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency(s.daily_target)}</td>
                          <td className="tnum px-4 py-2.5 text-right text-muted">{formatCurrency(s.promotion)}</td>
                          <td className="tnum px-4 py-2.5 text-right font-medium">{formatCurrency(s.total_ledger)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}
        </Card>
      )}

      {isAccountingTab && (
        <AccountingReportPanels
          tab={tab}
          cashQ={cashBookQ}
          bankQ={bankBookQ}
          debtorQ={debtorLedgerQ}
          creditorQ={creditorLedgerQ}
          ageingRecQ={ageingRecQ}
          ageingPayQ={ageingPayQ}
        />
      )}
    </div>
  );
}
