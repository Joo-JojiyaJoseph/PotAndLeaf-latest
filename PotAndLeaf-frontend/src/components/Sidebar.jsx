import { useEffect } from 'react';
import { createPortal } from 'react-dom';
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
  CubeIcon,
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
  ChevronRightIcon,
  XMarkIcon,
} from '@heroicons/react/24/outline';
import { useAuth } from '../context/AuthContext';
import { classNames } from '../lib/format';

const GROUPS = [
  {
    label: 'Main',
    items: [
      { key: 'dashboard', label: 'Dashboard', to: '/', icon: HomeIcon, end: true, anyOf: ['reports.view', 'sales.view', 'commission.view', 'commission.view_own', 'inventory.view'] },
      { key: 'purchase', label: 'Purchase', to: '/purchases', icon: ShoppingCartIcon, permission: 'purchases.view' },
      { key: 'purchase-orders', label: 'Purchase Orders', to: '/purchase-orders', icon: ClipboardDocumentListIcon, permission: 'po.view' },
      { key: 'inventory', label: 'Inventory', to: '/inventory', icon: CubeIcon, end: true, permission: 'inventory.view' },
      { key: 'batches', label: 'Batches & barcodes', to: '/inventory/batches', icon: QrCodeIcon, permission: 'inventory.view' },
      { key: 'damage', label: 'Damage Entry', to: '/damage-entries', icon: ExclamationTriangleIcon, permission: 'damage.view' },
      { key: 'purchase-returns', label: 'Purch. Returns', to: '/purchase-returns', icon: ArrowUturnLeftIcon, permission: 'purchase_returns.view' },
      { key: 'stock-verifications', label: 'Stock Count', to: '/stock-verifications', icon: ClipboardDocumentCheckIcon, permission: 'stock_verifications.view' },
      { key: 'bulk-splits', label: 'Bulk Split', to: '/bulk-splits', icon: ScissorsIcon, permission: 'bulk_splits.view' },
      { key: 'transfers', label: 'Transfers', to: '/transfers', icon: ArrowsRightLeftIcon, permission: 'transfers.view' },
      { key: 'production', label: 'Production', to: '/production', icon: BeakerIcon, permission: 'production.view' },
    ],
  },
  {
    label: 'Commerce',
    items: [
      { key: 'sales', label: 'Sales', to: '/sales', icon: CalculatorIcon, permission: 'sales.view' },
      { key: 'backorders', label: 'Backorders', to: '/backorders', icon: ClockIcon, permission: 'backorder.view' },
      { key: 'rentals', label: 'Plant Rental', to: '/rentals', icon: GiftIcon, permission: 'rental.view' },
      { key: 'customers', label: 'Customers', to: '/customers', icon: UsersIcon, permission: 'customers.view' },
      { key: 'loyalty', label: 'Loyalty', icon: SparklesIcon, to: '/loyalty', permission: 'loyalty.view' },
      { key: 'commission', label: 'Commission', to: '/commission', icon: CurrencyRupeeIcon, permission: 'commission.view' },
      { key: 'reports', label: 'Reports', to: '/reports', icon: ChartBarIcon, permission: 'reports.view' },
    ],
  },
  {
    label: 'Setup',
    items: [
      { key: 'suppliers', label: 'Suppliers', to: '/suppliers', icon: TruckIcon, permission: 'suppliers.view' },
      { key: 'payments', label: 'Payments', to: '/payments', icon: BanknotesIcon, permission: 'payments.view' },
      { key: 'receipts', label: 'Receipts', to: '/receipts', icon: ReceiptRefundIcon, permission: 'receipts.view' },
      { key: 'companies', label: 'Companies', to: '/companies', icon: BuildingOffice2Icon, superAdmin: true },
      { key: 'roles', label: 'Roles', to: '/roles', icon: ShieldCheckIcon, permission: 'roles.view' },
      { key: 'users', label: 'Users', to: '/users', icon: UserGroupIcon, permission: 'users.view' },
      { key: 'masters', label: 'Master data', to: '/masters', icon: TagIcon, anyOf: ['categories.view', 'subcategories.view', 'units.view'] },
      { key: 'products', label: 'Products', to: '/products', icon: TagIcon, permission: 'products.view' },
      { key: 'settings', label: 'Settings', to: '/settings', icon: TagIcon, anyOf: ['settings.view', 'api.view', 'api.manage'] },
    ],
  },
];

function itemVisible(item, { isSuperAdmin, showHo, can }) {
  if (item.superAdmin) return isSuperAdmin;
  if (item.hoOnly && !showHo) return false;
  if (item.anyOf?.length) return item.anyOf.some((p) => can(p));
  if (item.permission) return can(item.permission);
  return false;
}

function PotLeafMark() {
  return (
    <svg viewBox="0 0 32 32" className="size-7" aria-hidden>
      <path d="M16 5c6 2.2 10 7 10 12.2-5.2 1.4-8.8-1.1-10-4.6-1.2 3.5-4.8 6-10 4.6C6 12 10 7.2 16 5z" fill="var(--color-leaf)" />
      <path d="M16 13v14" stroke="var(--color-leaf-hover)" strokeWidth="1.8" strokeLinecap="round" fill="none" />
    </svg>
  );
}

function Item({ item, onNavigate }) {
  const Icon = item.icon;
  const base =
    'flex items-center gap-3 rounded-[14px] px-3 py-2.5 text-[13px] transition-all duration-150 min-h-11';

  if (item.soon) {
    return (
      <NavLink
        to={`/soon/${item.key}`}
        className={({ isActive }) =>
          classNames(base, isActive ? 'bg-leaf font-medium text-white shadow-soft' : 'text-ink/75 hover:bg-white/70 hover:text-ink')
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
        onClick={onNavigate}
        className={({ isActive }) =>
        classNames(
          base,
          isActive
            ? 'bg-leaf font-medium text-white shadow-[0_8px_18px_rgba(100,122,36,0.28)]'
            : 'text-ink/75 hover:bg-white/70 hover:text-ink',
        )
      }
    >
      {({ isActive }) => (
        <>
          <Icon className="size-[18px]" strokeWidth={1.6} />
          <span className="flex-1">{item.label}</span>
          {isActive ? <ChevronRightIcon className="size-4 shrink-0 opacity-90" /> : null}
        </>
      )}
    </NavLink>
  );
}

function SidebarBody({ onClose, showClose }) {
  const { isSuperAdmin, can } = useAuth();
  const showHo = isSuperAdmin || can('activity.view') || can('backup.view') || can('*');
  const ctx = { isSuperAdmin, showHo, can };

  return (
    <>
      <div className="flex items-center gap-2.5 px-4 py-4 sm:py-5">
        <span className="flex size-10 items-center justify-center rounded-2xl bg-white/70 shadow-soft">
          <PotLeafMark />
        </span>
        <div className="min-w-0 flex-1 leading-tight">
          <div className="text-sm font-semibold tracking-tight">Pot &amp; Leaf</div>
          <div className="text-[10px] font-medium uppercase tracking-[0.14em] text-muted">Cheerakuzhy Nurseries</div>
        </div>
        {showClose ? (
          <button
            type="button"
            onClick={onClose}
            className="flex size-9 shrink-0 items-center justify-center rounded-full glass-control text-ink"
            aria-label="Close menu"
          >
            <XMarkIcon className="size-5" />
          </button>
        ) : null}
      </div>

      <nav className="flex-1 space-y-5 overflow-y-auto px-3 pb-6">
        {GROUPS.map((group) => {
          const items = group.items.filter((item) => itemVisible(item, ctx));
          if (items.length === 0) return null;

          return (
            <div key={group.label}>
              <div className="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-muted">
                {group.label}
              </div>
              <div className="space-y-0.5">
                {items.map((item) => (
                  <Item key={item.key} item={item} onNavigate={onClose} />
                ))}
              </div>
            </div>
          );
        })}
      </nav>
    </>
  );
}

export default function Sidebar({ open, onClose }) {
  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  useEffect(() => {
    const mq = window.matchMedia('(min-width: 1024px)');
    const onChange = () => {
      if (mq.matches) onClose();
    };
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, [onClose]);

  return (
    <>
      <aside className="app-sidebar-rail glass-panel hidden w-60 shrink-0 flex-col lg:flex lg:relative lg:z-[2] lg:self-stretch lg:rounded-[28px]">
        <SidebarBody onClose={onClose} showClose={false} />
      </aside>
      {createPortal(
        <div className="lg:hidden">
          <div
            className={classNames(
              'fixed inset-0 z-[60] bg-ink/40 transition-opacity duration-200',
              open ? 'opacity-100' : 'pointer-events-none opacity-0',
            )}
            onClick={onClose}
            aria-hidden
          />
          <aside
            className={classNames(
              'glass-panel fixed inset-y-0 left-0 z-[70] flex h-dvh w-[min(18rem,calc(100vw-3.5rem))] flex-col rounded-none shadow-pop',
              'transition-transform duration-200 ease-out',
              open ? 'translate-x-0' : 'pointer-events-none -translate-x-full',
            )}
            aria-hidden={!open}
            inert={!open || undefined}
          >
            <SidebarBody onClose={onClose} showClose />
          </aside>
        </div>,
        document.body,
      )}
    </>
  );
}
