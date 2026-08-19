import {
  ChartBarIcon,
  ArrowTrendingUpIcon,
  TrophyIcon,
  DocumentTextIcon,
  BanknotesIcon,
  ScaleIcon,
  CalculatorIcon,
  TagIcon,
  TruckIcon,
  CurrencyRupeeIcon,
  UserGroupIcon,
  ClipboardDocumentListIcon,
  CubeIcon,
  UserIcon,
  ArchiveBoxIcon,
  ArrowsRightLeftIcon,
  ClockIcon,
  WalletIcon,
  BuildingLibraryIcon,
  BookOpenIcon,
  CalendarDaysIcon,
  ChartPieIcon,
} from '@heroicons/react/24/outline';

/** Report catalog — icon, label, permission flags. */
export const REPORT_TABS = [
  { value: 'dashboard', label: 'Dashboard', shortLabel: 'Dashboard', icon: ChartBarIcon },
  { value: 'sales_analytics', label: 'Sales Analytics', shortLabel: 'Sales', icon: ArrowTrendingUpIcon },
  { value: 'leaderboard', label: 'Leaderboard', shortLabel: 'Leaderboard', icon: TrophyIcon },
  { value: 'gst_reconciliation', label: 'GST Reconciliation', shortLabel: 'GST', icon: DocumentTextIcon, accounting: true },
  { value: 'commission_report', label: 'Commission Report', shortLabel: 'Commission', icon: BanknotesIcon, commission: true },
  { value: 'margin', label: 'Profit & Margin', shortLabel: 'Margin', icon: ScaleIcon, ho: true },
  { value: 'profit', label: 'Approx. Profit', shortLabel: 'Profit', icon: CalculatorIcon, ho: true },
  { value: 'price_levels', label: 'Sales by Price Tier', shortLabel: 'Price Tier', icon: TagIcon },
  { value: 'inventory_movement', label: 'Stock Movement', shortLabel: 'Stock Move', icon: ChartPieIcon, inventory: true },
  { value: 'rental_delivery', label: 'Rental Delivery', shortLabel: 'Rental Del.', icon: TruckIcon, rental: true },
  { value: 'rental_income', label: 'Rental Income', shortLabel: 'Rental Inc.', icon: CurrencyRupeeIcon, rental: true },
  { value: 'rental_current', label: 'Currently Rented', shortLabel: 'Rented Now', icon: ClipboardDocumentListIcon, rental: true },
  { value: 'rental_customer', label: 'Customer Rentals', shortLabel: 'Cust. Rental', icon: UserGroupIcon, rental: true },
  { value: 'production_summary', label: 'Production Summary', shortLabel: 'Production', icon: CubeIcon, production: true },
  { value: 'production_by_product', label: 'Production by Product', shortLabel: 'Prod. Product', icon: ArchiveBoxIcon, production: true },
  { value: 'production_by_supervisor', label: 'Production by Supervisor', shortLabel: 'Prod. Super.', icon: UserIcon, production: true },
  { value: 'production_batches', label: 'Production Batches', shortLabel: 'Batches', icon: ClipboardDocumentListIcon, production: true },
  { value: 'transfer_summary', label: 'Transfer Summary', shortLabel: 'Transfers', icon: ArrowsRightLeftIcon, transfer: true },
  { value: 'transfer_in_transit', label: 'In Transit', shortLabel: 'In Transit', icon: ClockIcon, transfer: true },
  { value: 'cash_book', label: 'Cash Book', shortLabel: 'Cash', icon: WalletIcon, accounting: true },
  { value: 'bank_book', label: 'Bank Book', shortLabel: 'Bank', icon: BuildingLibraryIcon, accounting: true },
  { value: 'debtor_ledger', label: 'Debtor Ledger', shortLabel: 'Debtors', icon: BookOpenIcon, accounting: true },
  { value: 'creditor_ledger', label: 'Creditor Ledger', shortLabel: 'Creditors', icon: BookOpenIcon, accounting: true },
  { value: 'ageing_receivables', label: 'Ageing (AR)', shortLabel: 'Ageing AR', icon: CalendarDaysIcon, accounting: true },
  { value: 'ageing_payables', label: 'Ageing (AP)', shortLabel: 'Ageing AP', icon: CalendarDaysIcon, accounting: true },
];

export function filterVisibleTabs(tabs, { canHo, canRentalReports, canProductionReports, canTransferReports, canAccounting, canCommissionReport, canInventory }) {
  return tabs.filter((t) => {
    if (t.rental) return canRentalReports;
    if (t.production) return canProductionReports;
    if (t.transfer) return canTransferReports;
    if (t.accounting) return canAccounting;
    if (t.commission) return canCommissionReport;
    if (t.inventory) return canInventory;
    if (t.ho) return canHo;
    return true;
  });
}

export function tabMeta(value) {
  return REPORT_TABS.find((t) => t.value === value) ?? REPORT_TABS[0];
}
