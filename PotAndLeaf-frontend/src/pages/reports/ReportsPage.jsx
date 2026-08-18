import { useMemo, useState } from 'react';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { ArrowDownTrayIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../lib/toast';
import { Button, Card, StatCard, Spinner, Badge } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';
import { downloadWithParams } from '../../lib/pdfDownload';

const iso = (d) => d.toISOString().slice(0, 10);
const daysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return iso(d); };
const PRESETS = [{ label: '7d', days: 6 }, { label: '30d', days: 29 }, { label: '90d', days: 89 }];
const TABS = [
  { value: 'dashboard', label: 'Dashboard' },
  { value: 'margin', label: 'Profit & Margin' },
  { value: 'profit', label: 'Approx. Profit' },
  { value: 'price_levels', label: 'Sales by price tier' },
  { value: 'rental_delivery', label: 'Rental delivery', rental: true },
  { value: 'rental_income', label: 'Rental income', rental: true },
  { value: 'rental_current', label: 'Currently rented', rental: true },
  { value: 'rental_customer', label: 'Customer rentals', rental: true },
];
const selectCls = 'h-9 rounded-lg border border-line bg-surface px-2 text-sm';
const statusTone = { active: 'active', returned: 'inactive', overdue: 'blocked', expected: 'warning', cancelled: 'blocked', draft: 'inactive' };

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

function ExportButtons({ onPdf, onExcel }) {
  return (
    <div className="flex items-center gap-2">
      <Button variant="outline" size="sm" onClick={onPdf}><ArrowDownTrayIcon className="size-4" /> Export PDF</Button>
      <Button variant="outline" size="sm" onClick={onExcel}><ArrowDownTrayIcon className="size-4" /> Export Excel</Button>
    </div>
  );
}

export default function ReportsPage() {
  const { activeCompany, can, isSuperAdmin } = useAuth();
  const toast = useToast();
  const [tab, setTab] = useState('dashboard');
  const [range, setRange] = useState({ from: daysAgo(29), to: iso(new Date()) });
  const [reportCompanyId, setReportCompanyId] = useState('');
  const [period, setPeriod] = useState('daily');
  const [sortKey, setSortKey] = useState('margin_pct');
  const [locationId, setLocationId] = useState('');
  const [customerId, setCustomerId] = useState('');

  const companyParam = reportCompanyId || undefined;
  const locationParam = locationId || undefined;

  const canHo = isSuperAdmin || can('reports.margin') || can('reports.profit') || can('products.view_cost') || can('*');
  const canRentalReports = isSuperAdmin || can('*') || (can('reports.view') && can('rental.view'));
  const isRentalTab = tab.startsWith('rental_');
  const hideDateRange = tab === 'rental_current';

  const { data: formData } = useQuery({
    queryKey: ['reports-form-data', activeCompany?.id],
    queryFn: () => api.get('/reports/form-data').then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });

  const dashQ = useQuery({
    queryKey: ['reports-dashboard', activeCompany?.id, reportCompanyId, range.from, range.to],
    queryFn: () => api.get('/reports/dashboard', { params: { ...range, company_id: companyParam } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'dashboard',
    placeholderData: keepPreviousData,
  });

  const marginQ = useQuery({
    queryKey: ['reports-margin', activeCompany?.id, reportCompanyId, range.from, range.to],
    queryFn: () => api.get('/reports/margin', { params: { ...range, company_id: companyParam } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'margin' && canHo,
    placeholderData: keepPreviousData,
  });

  const profitQ = useQuery({
    queryKey: ['reports-profit', activeCompany?.id, reportCompanyId, range.from, range.to, period],
    queryFn: () => api.get('/reports/profit', {
      params: { ...range, period, company_id: companyParam },
    }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && tab === 'profit' && canHo,
    placeholderData: keepPreviousData,
  });

  const priceLevelQ = useQuery({
    queryKey: ['reports-price-levels', activeCompany?.id, reportCompanyId, range.from, range.to],
    queryFn: () => api.get('/reports/price-levels', { params: { ...range, company_id: companyParam } }).then((r) => r.data.data),
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

  const companies = formData?.companies ?? [];
  const locations = formData?.locations ?? [];
  const customers = formData?.customers ?? [];
  const reportCompany = companies.find((c) => String(c.id) === String(reportCompanyId)) ?? activeCompany;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Reports</h1>
          <p className="text-sm text-muted">Business summary{reportCompany ? ` for ${reportCompany.name}` : ''}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {isRentalTab && (
            <select value={locationId} onChange={(e) => setLocationId(e.target.value)} className={selectCls}>
              <option value="">All branches</option>
              {locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
            </select>
          )}
          {tab === 'rental_customer' && (
            <select value={customerId} onChange={(e) => setCustomerId(e.target.value)} className={selectCls}>
              <option value="">Select customer</option>
              {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          )}
          {!hideDateRange && (
            <>
              <div className="flex overflow-hidden rounded-lg border border-line">
                {PRESETS.map((p) => {
                  const on = range.from === daysAgo(p.days) && range.to === iso(new Date());
                  return (
                    <button
                      key={p.label}
                      type="button"
                      onClick={() => setRange({ from: daysAgo(p.days), to: iso(new Date()) })}
                      className={'px-3 py-1.5 text-sm ' + (on ? 'bg-leaf text-white' : 'bg-surface text-muted hover:text-ink')}
                    >
                      {p.label}
                    </button>
                  );
                })}
              </div>
              <input type="date" value={range.from} onChange={(e) => setRange((r) => ({ ...r, from: e.target.value }))} className={selectCls} />
              <span className="text-muted">–</span>
              <input type="date" value={range.to} onChange={(e) => setRange((r) => ({ ...r, to: e.target.value }))} className={selectCls} />
            </>
          )}
        </div>
      </div>

      <div className="flex flex-wrap gap-1 border-b border-line">
        {TABS.filter((t) => {
          if (t.rental) return canRentalReports;
          if (t.value === 'dashboard' || t.value === 'price_levels') return true;
          return canHo;
        }).map((t) => (
          <button
            key={t.value}
            type="button"
            onClick={() => setTab(t.value)}
            className={'border-b-2 px-3 py-2 text-sm transition-colors ' + (tab === t.value ? 'border-leaf font-medium text-leaf' : 'border-transparent text-muted hover:text-ink')}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'dashboard' && (
        <>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <ExportButtons
              onPdf={() => exportFile('/reports/dashboard/export', { ...range, company_id: companyParam, format: 'pdf' }, `dashboard-${range.from}.pdf`, 'application/pdf')}
              onExcel={() => exportFile('/reports/dashboard/export', { ...range, company_id: companyParam, format: 'excel' }, `dashboard-${range.from}.csv`, 'text/csv')}
            />
          </div>
          {dashQ.isLoading ? <div className="flex justify-center py-20"><Spinner className="size-6" /></div>
            : dashQ.isError || !dashQ.data ? <Card className="px-4 py-16 text-center text-sm text-muted">Couldn't load reports.</Card>
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
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
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
                    <div className="mt-1 flex items-baseline justify-between text-sm"><span className="text-muted">Invoiced in range</span><span className="tnum font-medium">{formatCurrency(dashQ.data.rentals.invoiced)}</span></div>
                  </Card>
                </div>
              </>
            )}
        </>
      )}

      {tab === 'margin' && (
        <>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex gap-2">
              <select value={sortKey} onChange={(e) => setSortKey(e.target.value)} className={selectCls}>
                <option value="margin_pct">Sort by margin %</option>
                <option value="margin">Sort by margin ₹</option>
                <option value="revenue">Sort by revenue</option>
              </select>
            </div>
            <ExportButtons
              onPdf={() => exportFile('/reports/margin/export', { ...range, company_id: companyParam, format: 'pdf' }, 'margin.pdf', 'application/pdf')}
              onExcel={() => exportFile('/reports/margin/export', { ...range, company_id: companyParam, format: 'excel' }, 'margin.csv', 'text/csv')}
            />
          </div>
          <Card className="overflow-hidden">
            {marginQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
              : marginRows.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No margin data in this range.</div>
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
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex gap-2">
              <select value={period} onChange={(e) => setPeriod(e.target.value)} className={selectCls}>
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
                <option value="yearly">Yearly</option>
              </select>
              </div>
            <ExportButtons
              onPdf={() => exportFile('/reports/profit/export', { ...range, period, company_id: companyParam, format: 'pdf' }, 'profit.pdf', 'application/pdf')}
              onExcel={() => exportFile('/reports/profit/export', { ...range, period, company_id: companyParam, format: 'excel' }, 'profit.csv', 'text/csv')}
            />
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
          <div className="flex justify-end">
            <ExportButtons
              onPdf={() => exportFile('/reports/rental/delivery/export', { ...range, location_id: locationParam, format: 'pdf' }, 'rental-delivery.pdf', 'application/pdf')}
              onExcel={() => exportFile('/reports/rental/delivery/export', { ...range, location_id: locationParam, format: 'excel' }, 'rental-delivery.csv', 'text/csv')}
            />
          </div>
          <Card className="overflow-hidden">
            {rentalDeliveryQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
              : (rentalDeliveryQ.data?.data?.length ?? 0) === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No rental deliveries in this range.</div>
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
          <div className="flex flex-wrap items-center justify-between gap-2">
            <select value={period} onChange={(e) => setPeriod(e.target.value)} className={selectCls}>
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
            </select>
            <ExportButtons
              onPdf={() => exportFile('/reports/rental/income/export', { ...range, location_id: locationParam, period, format: 'pdf' }, 'rental-income.pdf', 'application/pdf')}
              onExcel={() => exportFile('/reports/rental/income/export', { ...range, location_id: locationParam, period, format: 'excel' }, 'rental-income.csv', 'text/csv')}
            />
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
        <>
          <div className="flex justify-end">
            <ExportButtons
              onPdf={() => exportFile('/reports/rental/current/export', { location_id: locationParam, format: 'pdf' }, 'rental-current.pdf', 'application/pdf')}
              onExcel={() => exportFile('/reports/rental/current/export', { location_id: locationParam, format: 'excel' }, 'rental-current.csv', 'text/csv')}
            />
          </div>
          <Card className="overflow-hidden">
            {rentalCurrentQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
              : (rentalCurrentQ.data?.data?.length ?? 0) === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">Nothing currently out on rent.</div>
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
        </>
      )}

      {tab === 'rental_customer' && (
        <>
          <div className="flex justify-end">
            {customerId && (
              <ExportButtons
                onPdf={() => exportFile(`/reports/rental/customer/${customerId}/export`, { ...range, location_id: locationParam, format: 'pdf' }, 'rental-customer.pdf', 'application/pdf')}
                onExcel={() => exportFile(`/reports/rental/customer/${customerId}/export`, { ...range, location_id: locationParam, format: 'excel' }, 'rental-customer.csv', 'text/csv')}
              />
            )}
          </div>
          <Card className="overflow-hidden">
            {!customerId ? <div className="px-4 py-16 text-center text-sm text-muted">Select a customer to view rental history.</div>
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
        </>
      )}
    </div>
  );
}
