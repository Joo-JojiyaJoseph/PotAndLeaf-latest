import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import {
  ArrowRightIcon, ArrowTrendingUpIcon, BanknotesIcon, CubeIcon,
  ShoppingCartIcon, PlusCircleIcon, ChartBarIcon, QrCodeIcon, TagIcon, BuildingOffice2Icon, TruckIcon, UsersIcon,
  HomeIcon,
} from '@heroicons/react/24/outline';
import api from '../lib/api';
import { useAuth } from '../context/AuthContext';
import useCompanyFilter from '../hooks/useCompanyFilter';
import { Card, Spinner, StatCard, PageHeader } from '../components/ui';
import { formatCurrency, formatDate } from '../lib/format';

const iso = (d) => d.toISOString().slice(0, 10);
const daysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return iso(d); };

const QUICK = [
  { label: 'New sale', desc: 'Ring up a bill', to: '/sales/new', icon: BanknotesIcon },
  { label: 'New purchase', desc: 'Record a GRN', to: '/purchases/new', icon: ShoppingCartIcon },
  { label: 'Add product', desc: 'Create a catalogue item', to: '/products/new', icon: PlusCircleIcon },
  { label: 'Reports', desc: 'Sales, stock and dues', to: '/reports', icon: ChartBarIcon },
  { label: 'Masters', desc: 'Categories, units', to: '/masters', icon: TagIcon },
  { label: 'Companies', desc: 'Manage companies', to: '/companies', icon: BuildingOffice2Icon },
  { label: 'Suppliers', desc: 'Manage suppliers', to: '/suppliers', icon: TruckIcon },
];

export default function Dashboard() {
  const { activeCompany, isSuperAdmin, can, user } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const range = { from: daysAgo(29), to: iso(new Date()) };
  const canReports = isSuperAdmin || can('reports.view') || can('*');
  const canSales = isSuperAdmin || can('sales.view') || can('*');
  const canIncentive = isSuperAdmin || can('commission.view') || can('commission.view_own') || can('*');

  const quickLinks = QUICK.filter((q) => {
    if (q.to === '/companies') return isSuperAdmin;
    if (q.to === '/products/new') return can('products.create');
    if (q.to === '/purchases/new') return can('purchases.create');
    if (q.to === '/sales/new') return can('sales.create');
    if (q.to === '/reports') return canReports;
    if (q.to === '/masters') return can('categories.view') || can('subcategories.view') || can('units.view');
    if (q.to === '/suppliers') return can('suppliers.view');
    return true;
  });

  const dashQ = useQuery({
    queryKey: ['dashboard', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/dashboard', { params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });
  const repQ = useQuery({
    queryKey: ['dashboard-reports', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/reports/dashboard', { params: { ...companyParams, ...range } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && canReports,
    retry: false,
  });
  const salesQ = useQuery({
    queryKey: ['dashboard-sales', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/sales', { params: { ...companyParams, per_page: 6 } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany) && canSales,
    retry: false,
  });
  const incentiveQ = useQuery({
    queryKey: ['dashboard-incentive', activeCompany?.id, user?.id],
    queryFn: () => api.get('/commission/daily-summary', { params: { user_id: user.id, date: iso(new Date()) } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany && user?.id) && canIncentive && filterCompanyId !== 'all',
    retry: false,
  });

  const rep = repQ.data;
  const cards = dashQ.data?.cards ?? [];
  const lowStock = cards.find((c) => c.key === 'low_stock')?.value ?? 0;
  const recent = Array.isArray(salesQ.data) ? salesQ.data : (salesQ.data?.data ?? []);

  return (
    <div className="p-4 sm:p-6">
      <PageHeader
        icon={HomeIcon}
        title="Overview"
        subtitle={`Dashboard${companyHint}`}
        actions={<Filter />}
        className="mb-4"
      />

      {dashQ.isLoading ? (
        <div className="flex justify-center py-20"><Spinner className="size-6" /></div>
      ) : (
        <div className="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-12">
          
          <div className="space-y-5 lg:col-span-8">
            {canReports && (
              <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
                <StatCard
                  label="Sales (30 days)" value={rep ? formatCurrency(rep.sales.total) : '—'}
                  sub={rep ? `${rep.sales.count} invoices` : 'Loading…'}
                  tone="good"
                  icon={BanknotesIcon}
                />
                <StatCard
                  label="Receivables" value={rep ? formatCurrency(rep.receivables) : '—'}
                  sub="owed by customers"
                  icon={ArrowTrendingUpIcon}
                />
                <StatCard
                  label="Payables" value={rep ? formatCurrency(rep.payables) : '—'}
                  sub="owed to suppliers"
                  tone="warn"
                  icon={ShoppingCartIcon}
                />
                <StatCard
                  label="Stock value" value={rep ? formatCurrency(rep.inventory?.stock_value) : '—'}
                  sub={`${cards.find((c) => c.key === 'products')?.value ?? 0} products`}
                  icon={CubeIcon}
                />
              </div>
            )}

            {canIncentive && incentiveQ.data && (
              <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <StatCard label="Today's sales" value={formatCurrency(incentiveQ.data.sales_total)} />
                <StatCard label="Commission" value={formatCurrency(incentiveQ.data.sales_commission)} tone="good" />
                <StatCard label="Bonuses" value={formatCurrency((incentiveQ.data.daily_target_bonus || 0) + (incentiveQ.data.promotion_bonus || 0))} />
                <StatCard
                  label="Total incentive"
                  value={formatCurrency(incentiveQ.data.total_incentive)}
                  sub={incentiveQ.data.daily_target ? `Target ${formatCurrency(incentiveQ.data.daily_target)}` : null}
                  tone="good"
                />
              </div>
            )}

            {lowStock > 0 && can('inventory.view') && (
              <Link to="/inventory" className="flex items-center gap-3 rounded-2xl bg-amber-soft px-4 py-3 text-sm text-amber">
                <ArrowTrendingUpIcon className="size-5 shrink-0" />
                <span><b>{lowStock}</b> product{lowStock === 1 ? '' : 's'} at or below reorder level.</span>
                <ArrowRightIcon className="ml-auto size-4" />
              </Link>
            )}

            {/* Quick actions */}
            <div>
              <h2 className="mb-3 text-sm font-semibold text-ink">Quick actions</h2>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {quickLinks.map((q) => {
                  const Icon = q.icon;
                  return (
                    <Link key={q.to} to={q.to}
                      className="group flex items-center gap-4 glass-card p-4 transition-all duration-150 hover:-translate-y-0.5">
                      <span className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-leaf-soft text-leaf">
                        <Icon className="size-5" />
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm font-semibold text-ink">{q.label}</span>
                        <span className="block truncate text-xs text-muted">{q.desc}</span>
                      </span>
                      <ArrowRightIcon className="size-4 text-faint transition-transform group-hover:translate-x-0.5 group-hover:text-leaf" />
                    </Link>
                  );
                })}
              </div>
            </div>
          </div>

          {/* Right rail — recent activity */}
          <div className="lg:col-span-4">
            {canSales && (
            <Card className="overflow-hidden">
              <div className="flex items-center justify-between border-b border-line px-4 py-3">
                <h2 className="text-sm font-semibold text-ink">Recent sales</h2>
                <Link to="/sales" className="inline-flex items-center gap-1 text-xs font-medium text-leaf hover:text-leaf-hover">View all <ArrowRightIcon className="size-3.5" /></Link>
              </div>
              {salesQ.isLoading ? (
                <div className="flex justify-center py-12"><Spinner className="size-5" /></div>
              ) : recent.length === 0 ? (
                <div className="px-4 py-12 text-center text-sm text-muted">No sales yet.</div>
              ) : (
                <ul className="divide-y divide-line/70">
                  {recent.map((s) => (
                    <li key={s.id}>
                      <Link to={`/sales/${s.id}`} className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-leaf-soft/40">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-leaf-soft text-[11px] font-semibold text-leaf">
                          {(s.customer_name ?? 'W').slice(0, 2).toUpperCase()}
                        </span>
                        <span className="min-w-0 flex-1">
                          <span className="block truncate text-sm font-medium text-ink">{s.customer_name ?? 'Walk-in'}</span>
                          <span className="block text-xs text-muted">{s.sale_no} · {formatDate(s.sale_date)}</span>
                        </span>
                        <span className="tnum text-sm font-semibold text-ink">{formatCurrency(s.grand_total)}</span>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
            )}

            <Card className="mt-5 p-5">
              <div className="flex items-center gap-3">
                <span className="flex size-10 items-center justify-center rounded-2xl bg-leaf text-white"><CubeIcon className="size-5" /></span>
                <div>
                  <div className="text-sm font-semibold text-ink">{cards.find((c) => c.key === 'products')?.value ?? 0} products</div>
                  <div className="text-xs text-muted">{cards.find((c) => c.key === 'suppliers')?.value ?? 0} suppliers · {cards.find((c) => c.key === 'members')?.value ?? 0} users</div>
                </div>
              </div>
              <Link to="/products" className="mt-4 flex items-center justify-center gap-1.5 rounded-xl glass-control py-2 text-sm font-medium text-ink transition-colors hover:bg-white/90">
                Manage catalogue <ArrowRightIcon className="size-4" />
              </Link>
            </Card>
          </div>
        </div>
      )}
    </div>
  );
}
