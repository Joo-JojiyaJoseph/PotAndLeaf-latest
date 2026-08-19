import { NavLink } from 'react-router-dom';
import {
  ArrowsRightLeftIcon,
  ArrowUturnLeftIcon,
  ExclamationTriangleIcon,
  ClipboardDocumentCheckIcon,
  ScissorsIcon,
  BeakerIcon,
  CalculatorIcon,
  ChartBarIcon,
  Cog6ToothIcon,
  CubeIcon,
  ServerStackIcon,
  SignalIcon,
  GiftIcon,
  HomeIcon,
  ReceiptRefundIcon,
  CurrencyRupeeIcon,
  BanknotesIcon,
  ShieldCheckIcon,
  ShoppingCartIcon,
  SparklesIcon,
  TagIcon,
  TruckIcon,
  UserGroupIcon,
  UsersIcon,
  BuildingOffice2Icon,
  ClipboardDocumentListIcon,
  ClockIcon,
  QrCodeIcon,
} from '@heroicons/react/24/outline';
import { useAuth } from '../context/AuthContext';
import { useConfirm } from '../lib/confirm';
import { useToast } from '../lib/toast';
import { classNames } from '../lib/format';
import { CompanySelectMenuBlock } from './CompanySelectMenu';

const GROUPS = [
  {
    label: 'Main',
    items: [
      { key: 'dashboard', label: 'Dashboard', to: '/', icon: HomeIcon, end: true },
      { key: 'purchase', label: 'Purchase', to: '/purchases', icon: ShoppingCartIcon },
      { key: 'purchase-orders', label: 'Purchase Orders', to: '/purchase-orders', icon: ClipboardDocumentListIcon },
      { key: 'inventory', label: 'Inventory', to: '/inventory', icon: CubeIcon, end: true },
      { key: 'batches', label: 'Batches & barcodes', to: '/inventory/batches', icon: QrCodeIcon },
      { key: 'damage', label: 'Damage Entry', to: '/damage-entries', icon: ExclamationTriangleIcon },
      { key: 'purchase-returns', label: 'Purch. Returns', to: '/purchase-returns', icon: ArrowUturnLeftIcon },
      { key: 'stock-verifications', label: 'Stock Count', to: '/stock-verifications', icon: ClipboardDocumentCheckIcon },
      { key: 'bulk-splits', label: 'Bulk Split', to: '/bulk-splits', icon: ScissorsIcon },
      { key: 'transfers', label: 'Transfers', to: '/transfers', icon: ArrowsRightLeftIcon },
      { key: 'production', label: 'Production', to: '/production', icon: BeakerIcon },
    ],
  },
  {
    label: 'Commerce',
    items: [
      { key: 'sales', label: 'Sales', to: '/sales', icon: CalculatorIcon },
      { key: 'backorders', label: 'Backorders', to: '/backorders', icon: ClockIcon, permission: 'backorder.view' },
      { key: 'rentals', label: 'Plant Rental', to: '/rentals', icon: GiftIcon },
      { key: 'customers', label: 'Customers', to: '/customers', icon: UsersIcon },
      { key: 'loyalty', label: 'Loyalty', icon: SparklesIcon, to: '/loyalty', permission: 'loyalty.view' },
      { key: 'commission', label: 'Commission', to: '/commission', icon: CurrencyRupeeIcon, permission: 'commission.view' },
    ],
  },
  {
    label: 'Setup',
    items: [
      { key: 'suppliers', label: 'Suppliers', to: '/suppliers', icon: TruckIcon },
      { key: 'payments', label: 'Payments', to: '/payments', icon: BanknotesIcon },
      { key: 'receipts', label: 'Receipts', to: '/receipts', icon: ReceiptRefundIcon },
      // { key: 'commission', label: 'Commission', to: '/commission', icon: CurrencyRupeeIcon },
       { key: 'companies', label: 'Companies', to: '/companies', icon: BuildingOffice2Icon, superAdmin: true },
       { key: 'roles', label: 'Roles', to: '/roles', icon: ShieldCheckIcon },
      { key: 'users', label: 'Users', to: '/users', icon: UserGroupIcon },
      { key: 'masters', label: 'Master data', to: '/masters', icon: TagIcon },
      { key: 'products', label: 'Products', to: '/products', icon: TagIcon },
      
     
      { key: 'reports', label: 'Reports', to: '/reports', icon: ChartBarIcon },
      // { key: 'activity', label: 'Activity Monitor', to: '/activity-monitoring', icon: SignalIcon, hoOnly: true },
      // { key: 'backups', label: 'Backups', to: '/backups', icon: ServerStackIcon, hoOnly: true },
      // { key: 'advance-orders', label: 'Advance Orders', to: '/advance-orders', icon: CalendarDaysIcon },
      // { key: 'settings', label: 'Settings', to: '/settings', icon: Cog6ToothIcon },
    ],
  },
];

function PotLeafMark() {
  return (
    <svg viewBox="0 0 32 32" className="size-7" aria-hidden>
      {/* leaf */}
      <path d="M16 4c5 2 8 6 8 10-4 1-7-1-8-4-1 3-4 5-8 4 0-4 3-8 8-10z" fill="var(--color-leaf)" />
      {/* pot */}
      <path d="M9 19h14l-1.6 7.2a2 2 0 0 1-2 1.6h-6.8a2 2 0 0 1-2-1.6L9 19z" fill="var(--color-terracotta)" />
      <rect x="8" y="17.4" width="16" height="2.2" rx="1.1" fill="var(--color-terracotta)" />
    </svg>
  );
}

function CompanySwitcher() {
  const { companies, companyId, selectCompany, isSuperAdmin } = useAuth();
  const confirm = useConfirm();
  const toast = useToast();

  if (companies.length === 0) return null;

  const options = companies.map((c) => ({
    value: c.id,
    label: c.name,
    sublabel: c.code ? c.code.toUpperCase() : undefined,
  }));

  async function onBeforeChange(next) {
    if (!isSuperAdmin) return true;
    const name = companies.find((c) => String(c.id) === String(next))?.name ?? 'this company';
    return confirm({
      title: 'Switch company?',
      message: `The whole workspace will switch to ${name}. Any unsaved changes on the current screen may be lost.`,
      confirmLabel: 'Switch',
      tone: 'primary',
    });
  }

  function onChange(next) {
    selectCompany(next);
    const name = companies.find((c) => String(c.id) === String(next))?.name ?? 'this company';
    toast.info(`Switched to ${name}`);
  }

  return (
    <CompanySelectMenuBlock
      value={companyId ?? ''}
      options={options}
      onChange={onChange}
      onBeforeChange={onBeforeChange}
      hint="Used for creating and updating records."
    />
  );
}

function Item({ item }) {
  const Icon = item.icon;
  const base =
    'flex items-center gap-3 rounded-xl px-3 py-2.5 text-[13px] transition-all';

  if (item.soon) {
    return (
      <NavLink
        to={`/soon/${item.key}`}
        className={({ isActive }) =>
          classNames(base, isActive ? 'bg-surface text-ink shadow-soft' : 'text-muted hover:bg-surface hover:text-ink')
        }
      >
        <Icon className="size-[18px]" />
        <span className="flex-1">{item.label}</span>
        <span className="font-mono text-[9px] uppercase tracking-wide text-muted/70">soon</span>
      </NavLink>
    );
  }

  return (
    <NavLink
      to={item.to}
      end={item.end}
      className={({ isActive }) =>
        classNames(
          base,
          isActive
            ? 'bg-leaf font-medium text-white shadow-soft'
            : 'text-muted hover:bg-surface hover:text-ink',
        )
      }
    >
      <Icon className="size-[18px]" />
      {item.label}
    </NavLink>
  );
}

export default function Sidebar({ open, onClose }) {
  const { isSuperAdmin, can } = useAuth();
  const showHo = isSuperAdmin || can('activity.view') || can('backup.view') || can('*');
  return (
    <>
      {open && (
        <div className="fixed inset-0 z-30 bg-ink/30 lg:hidden" onClick={onClose} aria-hidden />
      )}
      <aside
        className={classNames(
          'fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-line bg-sidebar transition-transform lg:static lg:z-auto lg:translate-x-0',
          open ? 'translate-x-0' : '-translate-x-full',
        )}
      >
        <div className="flex items-center gap-2.5 px-4 py-4">
          <PotLeafMark />
          <div className="leading-tight">
            <div className="text-sm font-semibold">Pot &amp; Leaf</div>
            <div className="font-mono text-[10px] text-muted">Cheerakuzhy Nurseries</div>
          </div>
        </div>

        {/* <CompanySwitcher /> */}

        <nav className="flex-1 space-y-5 overflow-y-auto px-3 pb-6">
          {GROUPS.map((group) => (
            <div key={group.label}>
              <div className="mb-1.5 px-3 font-mono text-[10px] uppercase tracking-wider text-muted/70">
                {group.label}
              </div>
              <div className="space-y-0.5">
                {group.items
                  .filter((item) => !item.superAdmin || isSuperAdmin)
                  .filter((item) => !item.hoOnly || showHo)
                  .filter((item) => !item.permission || can(item.permission))
                  .map((item) => (
                    <Item key={item.key} item={item} />
                  ))}
              </div>
            </div>
          ))}
        </nav>
      </aside>
    </>
  );
}
