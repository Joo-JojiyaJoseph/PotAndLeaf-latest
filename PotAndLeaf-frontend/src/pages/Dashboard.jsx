import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import {
  ArrowRightIcon, ArrowTrendingUpIcon, BanknotesIcon, CubeIcon,
  ShoppingCartIcon, PlusCircleIcon, ChartBarIcon, QrCodeIcon, TagIcon, BuildingOffice2Icon, TruckIcon, UsersIcon,
} from '@heroicons/react/24/outline';
import api from '../lib/api';
import { useAuth } from '../context/AuthContext';
import useCompanyFilter from '../hooks/useCompanyFilter';
import { Card, Spinner } from '../components/ui';
import { formatCurrency, formatDate } from '../lib/format';

const iso = (d) => d.toISOString().slice(0, 10);
const daysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return iso(d); };

const QUICK = [
  // { label: 'New sale', desc: 'Ring up a bill at POS', to: '/sales/new', icon: BanknotesIcon },
  // { label: 'New purchase', desc: 'Record a GRN', to: '/purchases/new', icon: ShoppingCartIcon },
  { label: 'Add product', desc: 'Create a catalogue item', to: '/products/new', icon: PlusCircleIcon },
  // { label: 'Reports', desc: 'Sales, stock & dues', to: '/reports', icon: ChartBarIcon },
  // { label: 'Barcode labels', desc: 'Print a label sheet', to: '/products/labels', icon: QrCodeIcon },
  { label: 'Masters', desc: 'Categories, brands, units', to: '/masters', icon: TagIcon },
  { label: 'Companies', desc: 'Manage your companies', to: '/companies', icon: BuildingOffice2Icon },
  { label: 'Suppliers', desc: 'Manage your suppliers', to: '/suppliers', icon: TruckIcon },
];

function StatTile({ label, value, sub, gradient }) {
  return (
    <div className={'relative overflow-hidden rounded-3xl p-5 shadow-card ' + (gradient ?? 'bg-surface')}>
      <div className="microlabel text-faint">{label}</div>
      <div className="tnum mt-2 text-[28px] font-semibold leading-none text-ink">{value}</div>
      {sub && <div className="mt-2 text-xs text-muted">{sub}</div>}
    </div>
  );
}

export default function Dashboard() {
  const { activeCompany } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const range = { from: daysAgo(29), to: iso(new Date()) };

  const dashQ = useQuery({
    queryKey: ['dashboard', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/dashboard', { params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });
  const repQ = useQuery({
    queryKey: ['dashboard-reports', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/reports/dashboard', { params: { ...companyParams, ...range } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany), retry: false,
  });
  const salesQ = useQuery({
    queryKey: ['dashboard-sales', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/sales', { params: { ...companyParams, per_page: 6 } }).then((r) => r.data.data),
    enabled: Boolean(activeCompany), retry: false,
  });

  const rep = repQ.data;
  const cards = dashQ.data?.cards ?? [];
  const lowStock = cards.find((c) => c.key === 'low_stock')?.value ?? 0;
  const recent = salesQ.data ?? [];

  return (
    <div className="p-4 sm:p-6">
      {/* Header */}
      {/* <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-ink">Overview</h1>
          <p className="mt-0.5 text-sm text-muted">{activeCompany ? activeCompany.name : 'Select a company'} · last 30 days{companyHint}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Link to="/reports" className="inline-flex items-center gap-1.5 rounded-full bg-leaf px-4 py-2 text-sm font-medium text-white shadow-soft transition-colors hover:bg-leaf-hover">
            View full reports <ArrowRightIcon className="size-4" />
          </Link>
        </div>
      </div> */}

      {dashQ.isLoading ? (
        <div className="flex justify-center py-20"><Spinner className="size-6" /></div>
      ) : (
        <div className="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-12">
          
          <div className="space-y-5 lg:col-span-8">
{/*            
            <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
              <StatTile
                label="Sales" value={rep ? formatCurrency(rep.sales.total) : '—'}
                sub={rep ? `${rep.sales.count} invoices` : 'needs report access'}
              />
              <StatTile
                label="Receivables" value={rep ? formatCurrency(rep.receivables) : '—'}
                sub="owed by customers" gradient="bg-gradient-to-br from-leaf-soft to-surface"
              />
              <StatTile
                label="Payables" value={rep ? formatCurrency(rep.payables) : '—'}
                sub="owed to suppliers" gradient="bg-gradient-to-br from-terracotta-soft to-surface"
              />
              <StatTile
                label="Stock value" value={rep ? formatCurrency(rep.inventory.stock_value) : '—'}
                sub={`${cards.find((c) => c.key === 'products')?.value ?? 0} products`}
              />
            </div>

            {lowStock > 0 && (
              <Link to="/inventory" className="flex items-center gap-3 rounded-2xl bg-amber-soft px-4 py-3 text-sm text-amber shadow-soft transition-transform hover:scale-[1.01]">
                <ArrowTrendingUpIcon className="size-5 shrink-0" />
                <span><b>{lowStock}</b> product{lowStock === 1 ? '' : 's'} at or below reorder level — review inventory.</span>
                <ArrowRightIcon className="ml-auto size-4" />
              </Link>
            )} */}

            {/* Quick actions */}
            <div>
              <h2 className="mb-3 text-sm font-semibold text-ink">Quick actions</h2>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {QUICK.map((q) => {
                  const Icon = q.icon;
                  return (
                    <Link key={q.to} to={q.to}
                      className="group flex items-center gap-4 rounded-2xl bg-surface p-4 shadow-card transition-all hover:-translate-y-0.5 hover:shadow-pop">
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
            {/* <Card className="overflow-hidden rounded-3xl">
              <div className="flex items-center justify-between border-b border-line px-4 py-3">
                <h2 className="text-sm font-semibold text-ink">Recent sales</h2>
                <Link to="/sales" className="inline-flex items-center gap-1 text-xs font-medium text-leaf hover:text-leaf-hover">View all <ArrowRightIcon className="size-3.5" /></Link>
              </div>
              {salesQ.isLoading ? (
                <div className="flex justify-center py-12"><Spinner className="size-5" /></div>
              ) : recent.length === 0 ? (
                <div className="px-4 py-12 text-center text-sm text-muted">No sales yet. New bills will appear here.</div>
              ) : (
                <ul className="divide-y divide-line/70">
                  {recent.map((s) => (
                    <li key={s.id}>
                      <Link to={`/sales/${s.id}`} className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-sidebar/60">
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
            </Card> */}

            <Card className="mt-5 rounded-3xl p-5">
              <div className="flex items-center gap-3">
                <span className="flex size-10 items-center justify-center rounded-2xl bg-leaf text-white"><CubeIcon className="size-5" /></span>
                <div>
                  <div className="text-sm font-semibold text-ink">{cards.find((c) => c.key === 'products')?.value ?? 0} products</div>
                  <div className="text-xs text-muted">{cards.find((c) => c.key === 'suppliers')?.value ?? 0} suppliers · {cards.find((c) => c.key === 'members')?.value ?? 0} users</div>
                </div>
              </div>
              <Link to="/products" className="mt-4 flex items-center justify-center gap-1.5 rounded-xl border border-line py-2 text-sm font-medium text-ink transition-colors hover:bg-sidebar">
                Manage catalogue <ArrowRightIcon className="size-4" />
              </Link>
            </Card>
          </div>
        </div>
      )}
    </div>
  );
}
