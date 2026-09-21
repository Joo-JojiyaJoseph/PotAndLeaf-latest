import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import ProtectedRoute from './routes/ProtectedRoute';
import PermissionRoute from './routes/PermissionRoute';
import Login from './pages/Login';
import { Spinner } from './components/Spinner';

const AppShell = lazy(() => import('./components/AppShell'));
const Dashboard = lazy(() => import('./pages/Dashboard'));
const ProfilePage = lazy(() => import('./pages/ProfilePage'));
const ComingSoon = lazy(() => import('./pages/ComingSoon'));
const SettingsPage = lazy(() => import('./pages/settings/SettingsPage'));
const SuppliersList = lazy(() => import('./pages/suppliers/SuppliersList'));
const SupplierDetail = lazy(() => import('./pages/suppliers/SupplierDetail'));
const ProductsList = lazy(() => import('./pages/products/ProductsList'));
const ProductForm = lazy(() => import('./pages/products/ProductForm'));
const ProductDetail = lazy(() => import('./pages/products/ProductDetail'));
const CompaniesList = lazy(() => import('./pages/companies/CompaniesList'));
const CompanyDetail = lazy(() => import('./pages/companies/CompanyDetail'));
const UsersList = lazy(() => import('./pages/users/UsersList'));
const UserDetail = lazy(() => import('./pages/users/UserDetail'));
const RolesList = lazy(() => import('./pages/roles/RolesList'));
const MastersPage = lazy(() => import('./pages/masters/MastersPage'));
const BulkSplitsList = lazy(() => import('./pages/bulkSplits/BulkSplitsList'));
const BulkSplitForm = lazy(() => import('./pages/bulkSplits/BulkSplitForm'));
const BulkSplitDetail = lazy(() => import('./pages/bulkSplits/BulkSplitDetail'));
const CustomersList = lazy(() => import('./pages/customers/CustomersList'));
const CustomerDetail = lazy(() => import('./pages/customers/CustomerDetail'));
const LoyaltyPage = lazy(() => import('./pages/customers/LoyaltyPage'));
const SalesList = lazy(() => import('./pages/sales/SalesList'));
const SaleForm = lazy(() => import('./pages/sales/SaleForm'));
const SaleDetail = lazy(() => import('./pages/sales/SaleDetail'));
const SalesReturnsList = lazy(() => import('./pages/salesReturns/SalesReturnsList'));
const SalesReturnForm = lazy(() => import('./pages/salesReturns/SalesReturnForm'));
const SalesReturnDetail = lazy(() => import('./pages/salesReturns/SalesReturnDetail'));
const PaymentsList = lazy(() => import('./pages/payments/PaymentsList'));
const ReceiptsList = lazy(() => import('./pages/receipts/ReceiptsList'));
const CommissionList = lazy(() => import('./pages/commission/CommissionList'));
const TransfersList = lazy(() => import('./pages/transfers/TransfersList'));
const TransferForm = lazy(() => import('./pages/transfers/TransferForm'));
const TransferDetail = lazy(() => import('./pages/transfers/TransferDetail'));
const LocationsList = lazy(() => import('./pages/locations/LocationsList'));
const ProductionList = lazy(() => import('./pages/production/ProductionList'));
const ProductionForm = lazy(() => import('./pages/production/ProductionForm'));
const ProductionOrderDetail = lazy(() => import('./pages/production/ProductionOrderDetail'));
const RentalsList = lazy(() => import('./pages/rentals/RentalsList'));
const RentalForm = lazy(() => import('./pages/rentals/RentalForm'));
const RentalDetail = lazy(() => import('./pages/rentals/RentalDetail'));
const ReportsPage = lazy(() => import('./pages/reports/ReportsPage'));
const ActivityMonitoringPage = lazy(() => import('./pages/activity/ActivityMonitoringPage'));
const BackupDashboardPage = lazy(() => import('./pages/activity/BackupDashboardPage'));
const BarcodeLabelsPage = lazy(() => import('./pages/products/BarcodeLabelsPage'));
const PurchaseOrdersList = lazy(() => import('./pages/purchaseOrders/PurchaseOrdersList'));
const PurchaseOrderForm = lazy(() => import('./pages/purchaseOrders/PurchaseOrderForm'));
const PurchaseOrderReorderPage = lazy(() => import('./pages/purchaseOrders/PurchaseOrderReorderPage'));
const PurchaseOrderDetail = lazy(() => import('./pages/purchaseOrders/PurchaseOrderDetail'));
const AdvanceOrdersList = lazy(() => import('./pages/advanceOrders/AdvanceOrdersList'));
const AdvanceOrderForm = lazy(() => import('./pages/advanceOrders/AdvanceOrderForm'));
const AdvanceOrderDetail = lazy(() => import('./pages/advanceOrders/AdvanceOrderDetail'));
const BackordersList = lazy(() => import('./pages/backorders/BackordersList'));
const BackorderForm = lazy(() => import('./pages/backorders/BackorderForm'));
const BackorderDetail = lazy(() => import('./pages/backorders/BackorderDetail'));
const AccountingBookPage = lazy(() => import('./pages/accounting/AccountingBookPage'));
const AccountingVoucherForm = lazy(() => import('./pages/accounting/AccountingVoucherForm'));
const PurchasesList = lazy(() => import('./pages/purchases/PurchasesList'));
const PurchaseForm = lazy(() => import('./pages/purchases/PurchaseForm'));
const PurchaseDetail = lazy(() => import('./pages/purchases/PurchaseDetail'));
const InventoryList = lazy(() => import('./pages/inventory/InventoryList'));
const DamageEntriesPage = lazy(() => import('./pages/inventory/DamageEntriesPage'));
const BatchesPage = lazy(() => import('./pages/inventory/BatchesPage'));
const PurchaseReturnsList = lazy(() => import('./pages/purchaseReturns/PurchaseReturnsList'));
const PurchaseReturnForm = lazy(() => import('./pages/purchaseReturns/PurchaseReturnForm'));
const PurchaseReturnDetail = lazy(() => import('./pages/purchaseReturns/PurchaseReturnDetail'));
const StockVerificationsList = lazy(() => import('./pages/stockVerifications/StockVerificationsList'));
const StockVerificationForm = lazy(() => import('./pages/stockVerifications/StockVerificationForm'));
const StockVerificationDetail = lazy(() => import('./pages/stockVerifications/StockVerificationDetail'));

function RouteFallback() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-paper">
      <Spinner className="size-6" />
    </div>
  );
}

export default function App() {
  return (
    <Suspense fallback={<RouteFallback />}>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route element={<ProtectedRoute />}>
          <Route element={<AppShell />}>
            <Route index element={<PermissionRoute anyOf={['reports.view', 'sales.view', 'commission.view', 'commission.view_own', 'inventory.view']}><Dashboard /></PermissionRoute>} />
            <Route path="suppliers" element={<PermissionRoute permission="suppliers.view"><SuppliersList /></PermissionRoute>} />
            <Route path="suppliers/:id" element={<PermissionRoute permission="suppliers.view"><SupplierDetail /></PermissionRoute>} />
            <Route path="products" element={<PermissionRoute permission="products.view"><ProductsList /></PermissionRoute>} />
            <Route path="products/labels" element={<PermissionRoute permission="products.view"><BarcodeLabelsPage /></PermissionRoute>} />
            <Route path="products/new" element={<PermissionRoute permission="products.create"><ProductForm /></PermissionRoute>} />
            <Route path="products/:id/edit" element={<PermissionRoute permission="products.update"><ProductForm /></PermissionRoute>} />
            <Route path="products/:id" element={<PermissionRoute permission="products.view"><ProductDetail /></PermissionRoute>} />
            <Route path="purchases" element={<PermissionRoute permission="purchases.view"><PurchasesList /></PermissionRoute>} />
            <Route path="purchases/new" element={<PermissionRoute permission="purchases.create"><PurchaseForm /></PermissionRoute>} />
            <Route path="purchases/:id/edit" element={<PermissionRoute permission="purchases.update"><PurchaseForm /></PermissionRoute>} />
            <Route path="purchases/:id" element={<PermissionRoute permission="purchases.view"><PurchaseDetail /></PermissionRoute>} />
            <Route path="inventory" element={<PermissionRoute permission="inventory.view"><InventoryList /></PermissionRoute>} />
            <Route path="damage-entries" element={<PermissionRoute permission="damage.view"><DamageEntriesPage /></PermissionRoute>} />
            <Route path="inventory/batches" element={<PermissionRoute permission="inventory.view"><BatchesPage /></PermissionRoute>} />
            <Route path="purchase-returns" element={<PermissionRoute permission="purchase_returns.view"><PurchaseReturnsList /></PermissionRoute>} />
            <Route path="purchase-returns/new" element={<PermissionRoute permission="purchase_returns.create"><PurchaseReturnForm /></PermissionRoute>} />
            <Route path="purchase-returns/:id" element={<PermissionRoute permission="purchase_returns.view"><PurchaseReturnDetail /></PermissionRoute>} />
            <Route path="stock-verifications" element={<PermissionRoute permission="stock_verifications.view"><StockVerificationsList /></PermissionRoute>} />
            <Route path="stock-verifications/new" element={<PermissionRoute permission="stock_verifications.create"><StockVerificationForm /></PermissionRoute>} />
            <Route path="stock-verifications/:id" element={<PermissionRoute permission="stock_verifications.view"><StockVerificationDetail /></PermissionRoute>} />
            <Route path="companies" element={<PermissionRoute superAdmin><CompaniesList /></PermissionRoute>} />
            <Route path="companies/:id" element={<PermissionRoute superAdmin><CompanyDetail /></PermissionRoute>} />
            <Route path="users" element={<PermissionRoute permission="users.view"><UsersList /></PermissionRoute>} />
            <Route path="users/:id" element={<PermissionRoute permission="users.view"><UserDetail /></PermissionRoute>} />
            <Route path="roles" element={<PermissionRoute permission="roles.view"><RolesList /></PermissionRoute>} />
            <Route path="masters" element={<PermissionRoute anyOf={['categories.view', 'subcategories.view', 'units.view']}><MastersPage /></PermissionRoute>} />
            <Route path="profile" element={<ProfilePage />} />
            <Route path="bulk-splits" element={<PermissionRoute permission="bulk_splits.view"><BulkSplitsList /></PermissionRoute>} />
            <Route path="bulk-splits/new" element={<PermissionRoute permission="bulk_splits.create"><BulkSplitForm /></PermissionRoute>} />
            <Route path="bulk-splits/:id" element={<PermissionRoute permission="bulk_splits.view"><BulkSplitDetail /></PermissionRoute>} />
            <Route path="customers" element={<PermissionRoute permission="customers.view"><CustomersList /></PermissionRoute>} />
            <Route path="customers/:id" element={<PermissionRoute permission="customers.view"><CustomerDetail /></PermissionRoute>} />
            <Route path="loyalty" element={<PermissionRoute permission="loyalty.view"><LoyaltyPage /></PermissionRoute>} />
            <Route path="sales" element={<PermissionRoute permission="sales.view"><SalesList /></PermissionRoute>} />
            <Route path="sales/new" element={<PermissionRoute permission="sales.create"><SaleForm /></PermissionRoute>} />
            <Route path="sales/:id" element={<PermissionRoute permission="sales.view"><SaleDetail /></PermissionRoute>} />
            <Route path="sales-returns" element={<PermissionRoute permission="sales_returns.view"><SalesReturnsList /></PermissionRoute>} />
            <Route path="sales-returns/new" element={<PermissionRoute permission="sales_returns.create"><SalesReturnForm /></PermissionRoute>} />
            <Route path="sales-returns/:id" element={<PermissionRoute permission="sales_returns.view"><SalesReturnDetail /></PermissionRoute>} />
            <Route path="payments" element={<PermissionRoute permission="payments.view"><PaymentsList /></PermissionRoute>} />
            <Route path="receipts" element={<PermissionRoute permission="receipts.view"><ReceiptsList /></PermissionRoute>} />
            <Route path="commission" element={<PermissionRoute permission="commission.view"><CommissionList /></PermissionRoute>} />
            <Route path="transfers" element={<PermissionRoute permission="transfers.view"><TransfersList /></PermissionRoute>} />
            <Route path="transfers/new" element={<PermissionRoute permission="transfers.create"><TransferForm /></PermissionRoute>} />
            <Route path="transfers/:id" element={<PermissionRoute permission="transfers.view"><TransferDetail /></PermissionRoute>} />
            <Route path="locations" element={<PermissionRoute permission="locations.view"><LocationsList /></PermissionRoute>} />
            <Route path="production" element={<PermissionRoute permission="production.view"><ProductionList /></PermissionRoute>} />
            <Route path="production/new" element={<PermissionRoute permission="production.create"><ProductionForm /></PermissionRoute>} />
            <Route path="production/:id/edit" element={<PermissionRoute permission="production.create"><ProductionForm /></PermissionRoute>} />
            <Route path="production/orders/:id" element={<PermissionRoute permission="production.view"><ProductionOrderDetail /></PermissionRoute>} />
            <Route path="rentals" element={<PermissionRoute permission="rental.view"><RentalsList /></PermissionRoute>} />
            <Route path="rentals/new" element={<PermissionRoute permission="rental.create"><RentalForm /></PermissionRoute>} />
            <Route path="rentals/:id" element={<PermissionRoute permission="rental.view"><RentalDetail /></PermissionRoute>} />
            <Route path="reports" element={<PermissionRoute permission="reports.view"><ReportsPage /></PermissionRoute>} />
            <Route path="activity-monitoring" element={<PermissionRoute permission="activity.view"><ActivityMonitoringPage /></PermissionRoute>} />
            <Route path="backups" element={<PermissionRoute permission="backup.view"><BackupDashboardPage /></PermissionRoute>} />
            <Route path="purchase-orders" element={<PermissionRoute permission="po.view"><PurchaseOrdersList /></PermissionRoute>} />
            <Route path="purchase-orders/reorder" element={<PermissionRoute permission="po.create"><PurchaseOrderReorderPage /></PermissionRoute>} />
            <Route path="purchase-orders/new" element={<PermissionRoute permission="po.create"><PurchaseOrderForm /></PermissionRoute>} />
            <Route path="purchase-orders/:id" element={<PermissionRoute permission="po.view"><PurchaseOrderDetail /></PermissionRoute>} />
            <Route path="advance-orders" element={<PermissionRoute permission="advance.view"><AdvanceOrdersList /></PermissionRoute>} />
            <Route path="advance-orders/new" element={<PermissionRoute permission="advance.create"><AdvanceOrderForm /></PermissionRoute>} />
            <Route path="advance-orders/:id" element={<PermissionRoute permission="advance.view"><AdvanceOrderDetail /></PermissionRoute>} />
            <Route path="backorders" element={<PermissionRoute permission="backorder.view"><BackordersList /></PermissionRoute>} />
            <Route path="backorders/new" element={<PermissionRoute permission="backorder.create"><BackorderForm /></PermissionRoute>} />
            <Route path="backorders/:id" element={<PermissionRoute permission="backorder.view"><BackorderDetail /></PermissionRoute>} />
            <Route path="accounting/cash-book" element={<PermissionRoute permission="accounts.view"><AccountingBookPage /></PermissionRoute>} />
            <Route path="accounting/cash-book/new" element={<PermissionRoute permission="accounts.create"><AccountingVoucherForm /></PermissionRoute>} />
            <Route path="accounting/cash-book/:id/edit" element={<PermissionRoute permission="accounts.update"><AccountingVoucherForm /></PermissionRoute>} />
            <Route path="accounting/bank-book" element={<PermissionRoute permission="accounts.view"><AccountingBookPage /></PermissionRoute>} />
            <Route path="accounting/bank-book/new" element={<PermissionRoute permission="accounts.create"><AccountingVoucherForm /></PermissionRoute>} />
            <Route path="accounting/bank-book/:id/edit" element={<PermissionRoute permission="accounts.update"><AccountingVoucherForm /></PermissionRoute>} />
            <Route path="accounting/journal" element={<PermissionRoute permission="accounts.view"><AccountingBookPage /></PermissionRoute>} />
            <Route path="accounting/journal/new" element={<PermissionRoute permission="accounts.create"><AccountingVoucherForm /></PermissionRoute>} />
            <Route path="accounting/journal/:id/edit" element={<PermissionRoute permission="accounts.update"><AccountingVoucherForm /></PermissionRoute>} />
            <Route path="settings" element={<SettingsPage />} />
            <Route path="soon/:module" element={<ComingSoon />} />
          </Route>
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Suspense>
  );
}
