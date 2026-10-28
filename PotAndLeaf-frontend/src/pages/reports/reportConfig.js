import { ChartBarIcon,
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
  SparklesIcon,
  ChatBubbleLeftRightIcon,
  EnvelopeIcon,
} from '@heroicons/react/24/outline';

/** Report sections, in the order shown. */
export const REPORT_GROUPS = [
  { value: 'sales', label: 'Sales & profit' },
  { value: 'money', label: 'Money: cash, bank, dues, GST' },
  { value: 'stock', label: 'Stock & production' },
  { value: 'rental', label: 'Plant rental' },
  { value: 'people', label: 'Staff & customers' },
];

/** Report catalog — icon, label, permission flags; group, plain name and one-line help for the menu. */
export const REPORT_TABS = [
  { value: 'dashboard', label: 'Dashboard', shortLabel: 'Dashboard', icon: ChartBarIcon , group: 'sales', help: 'Sales, purchases, money owed and stock value for the dates you pick.', plain: 'Overview' },
  { value: 'sales_analytics', label: 'Sales Analytics', shortLabel: 'Sales', icon: ArrowTrendingUpIcon , group: 'sales', help: 'Sales by day, by payment mode, by product and this financial year.', plain: 'Sales details' },
  { value: 'leaderboard', label: 'Leaderboard', shortLabel: 'Leaderboard', icon: TrophyIcon , group: 'people', help: 'Staff ranked by sales, bills or commission.', plain: 'Best staff' },
  { value: 'gst_reconciliation', label: 'GST Reconciliation', shortLabel: 'GST', icon: DocumentTextIcon, accounting: true , group: 'money', help: 'GST collected on sales and paid on purchases, rate by rate.', plain: 'GST' },
  { value: 'commission_report', label: 'Commission Report', shortLabel: 'Commission', icon: BanknotesIcon, commission: true , group: 'people', help: 'What each staff member earned and was paid.', plain: 'Commission' },
  { value: 'loyalty_report', label: 'Loyalty Points', shortLabel: 'Loyalty', icon: SparklesIcon, loyalty: true , group: 'people', help: 'Points earned, used and left per customer.', plain: 'Loyalty points' },
  { value: 'whatsapp_report', label: 'WhatsApp Messages', shortLabel: 'WhatsApp', icon: ChatBubbleLeftRightIcon, whatsapp: true , group: 'people', help: 'Messages sent to customers and staff, and any that failed.', plain: 'WhatsApp sent' },
  { value: 'margin', label: 'Profit & Margin', shortLabel: 'Margin', icon: ScaleIcon, ho: true , group: 'sales', help: 'For each product: sales, cost and profit (margin %).', plain: 'Profit by product' },
  { value: 'profit', label: 'Approx. Profit', shortLabel: 'Profit', icon: CalculatorIcon, ho: true , group: 'sales', help: 'Sales − cost of goods − daily expense = profit.', plain: 'Approx. profit' },
  { value: 'internal_estimate', label: 'Internal Estimate (owner only)', shortLabel: 'Internal', icon: CalculatorIcon, owner: true , group: 'sales', help: 'Owner-only working of profit with extra costs.', plain: 'Owner estimate' },
  { value: 'price_levels', label: 'Sales by Price Tier', shortLabel: 'Price Tier', icon: TagIcon , group: 'sales', help: 'How much was sold at retail, wholesale and dealer prices.', plain: 'Sales by price type' },
  { value: 'inventory_movement', label: 'Stock Movement', shortLabel: 'Stock Move', icon: ChartPieIcon, inventory: true , group: 'stock', help: 'Which products sell fast, slowly or not at all.', plain: 'Fast / slow / dead stock' },
  { value: 'rental_delivery', label: 'Rental Delivery', shortLabel: 'Rental Del.', icon: TruckIcon, rental: true , group: 'rental', help: 'Plants delivered on rent in the dates you pick.', plain: 'Rentals sent out' },
  { value: 'rental_income', label: 'Rental Income', shortLabel: 'Rental Inc.', icon: CurrencyRupeeIcon, rental: true , group: 'rental', help: 'Rent bills made, paid and unpaid.', plain: 'Rent earned' },
  { value: 'rental_staff', label: 'Rental by Staff', shortLabel: 'Rental Staff', icon: UserIcon, rental: true , group: 'rental', help: 'Rentals and rent per staff member.', plain: 'Rentals by staff' },
  { value: 'rental_current', label: 'Currently Rented', shortLabel: 'Rented Now', icon: ClipboardDocumentListIcon, rental: true , group: 'rental', help: 'What is with customers today and for how many days.', plain: 'Plants out on rent now' },
  { value: 'rental_customer', label: 'Customer Rentals', shortLabel: 'Cust. Rental', icon: UserGroupIcon, rental: true , group: 'rental', help: 'All rentals of the customer you choose.', plain: 'One customer’s rentals' },
  { value: 'production_summary', label: 'Production Summary', shortLabel: 'Production', icon: CubeIcon, production: true , group: 'stock', help: 'Items made, their cost and profit.', plain: 'Production total' },
  { value: 'production_by_product', label: 'Production by Product', shortLabel: 'Prod. Product', icon: ArchiveBoxIcon, production: true , group: 'stock', help: 'How many of each item were made.', plain: 'Production by item' },
  { value: 'production_by_supervisor', label: 'Production by Supervisor', shortLabel: 'Prod. Super.', icon: UserIcon, production: true , group: 'stock', help: 'What each supervisor made.', plain: 'Production by supervisor' },
  { value: 'production_batches', label: 'Production Batches', shortLabel: 'Batches', icon: ClipboardDocumentListIcon, production: true , group: 'stock', help: 'Each batch made, with its barcode.', plain: 'Production batches' },
  { value: 'transfer_summary', label: 'Transfer Summary', shortLabel: 'Transfers', icon: ArrowsRightLeftIcon, transfer: true , group: 'stock', help: 'Stock sent and received between companies.', plain: 'Stock transfers' },
  { value: 'transfer_in_transit', label: 'In Transit', shortLabel: 'In Transit', icon: ClockIcon, transfer: true , group: 'stock', help: 'Sent but not yet received.', plain: 'Transfers on the way' },
  { value: 'cash_book', label: 'Cash Book', shortLabel: 'Cash', icon: WalletIcon, accounting: true , group: 'money', help: 'Cash in and out with running balance.', plain: 'Cash book' },
  { value: 'bank_book', label: 'Bank Book', shortLabel: 'Bank', icon: BuildingLibraryIcon, accounting: true , group: 'money', help: 'Bank, UPI, card and cheque in and out.', plain: 'Bank book' },
  { value: 'debtor_ledger', label: 'Customer Account (who owes you)', shortLabel: 'Customer dues', icon: BookOpenIcon, accounting: true , group: 'money', help: 'Everything with one customer and what they owe.', plain: 'Customer account' },
  { value: 'creditor_ledger', label: 'Supplier Account (what you owe)', shortLabel: 'Supplier dues', icon: BookOpenIcon, accounting: true , group: 'money', help: 'Everything with one supplier and what you owe.', plain: 'Supplier account' },
  { value: 'ageing_receivables', label: 'Customer dues by age', shortLabel: 'Old dues (cust.)', icon: CalendarDaysIcon, accounting: true , group: 'money', help: 'Who owes you, and how long it has been unpaid.', plain: 'Customer dues by age' },
  { value: 'ageing_payables', label: 'Supplier dues by age', shortLabel: 'Old dues (supp.)', icon: CalendarDaysIcon, accounting: true , group: 'money', help: 'What you owe, and how long it has been unpaid.', plain: 'Supplier dues by age' },
  { value: 'reorder', label: 'Reorder Report', shortLabel: 'Reorder', icon: ClipboardDocumentListIcon, po: true , group: 'stock', help: 'Products at or below their reorder level.', plain: 'What to buy' },
  { value: 'eod_management', label: 'EOD Summary', shortLabel: 'EOD', icon: EnvelopeIcon, eod: true , group: 'people', help: 'The daily summary sent to head office.', plain: 'End-of-day summary' },
];

export function filterVisibleTabs(tabs, { canHo, canRentalReports, canProductionReports, canTransferReports, canAccounting, canCommissionReport, canInventory, canPo, canLoyaltyReport, canWhatsappReport, canEod, canOwner }) {
  return tabs.filter((t) => {
    if (t.owner) return Boolean(canOwner);
    if (t.rental) return canRentalReports;
    if (t.production) return canProductionReports;
    if (t.transfer) return canTransferReports;
    if (t.accounting) return canAccounting;
    if (t.commission) return canCommissionReport;
    if (t.loyalty) return canLoyaltyReport;
    if (t.whatsapp) return canWhatsappReport;
    if (t.inventory) return canInventory;
    if (t.po) return canPo;
    if (t.ho) return canHo;
    if (t.eod) return canEod;
    return true;
  });
}

export function tabMeta(value) {
  return REPORT_TABS.find((t) => t.value === value) ?? REPORT_TABS[0];
}
