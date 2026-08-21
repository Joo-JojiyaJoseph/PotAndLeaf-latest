import { Navigate, Route, Routes } from 'react-router-dom';
import ProtectedRoute from './routes/ProtectedRoute';
import PermissionRoute from './routes/PermissionRoute';
import AppShell from './components/AppShell';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import ProfilePage from './pages/ProfilePage';
import ComingSoon from './pages/ComingSoon';
import SettingsPage from './pages/settings/SettingsPage';
import SuppliersList from './pages/suppliers/SuppliersList';
import SupplierDetail from './pages/suppliers/SupplierDetail';
import ProductsList from './pages/products/ProductsList';
import ProductForm from './pages/products/ProductForm';
import ProductDetail from './pages/products/ProductDetail';
import CompaniesList from './pages/companies/CompaniesList';
import CompanyDetail from './pages/companies/CompanyDetail';
import UsersList from './pages/users/UsersList';
import UserDetail from './pages/users/UserDetail';
import RolesList from './pages/roles/RolesList';
import MastersPage from './pages/masters/MastersPage';
import BulkSplitsList from './pages/bulkSplits/BulkSplitsList';
import BulkSplitForm from './pages/bulkSplits/BulkSplitForm';
import BulkSplitDetail from './pages/bulkSplits/BulkSplitDetail';
import CustomersList from './pages/customers/CustomersList';
import CustomerDetail from './pages/customers/CustomerDetail';
import LoyaltyPage from './pages/customers/LoyaltyPage';
import SalesList from './pages/sales/SalesList';
import SaleForm from './pages/sales/SaleForm';
import SaleDetail from './pages/sales/SaleDetail';
import SalesReturnsList from './pages/salesReturns/SalesReturnsList';
import SalesReturnForm from './pages/salesReturns/SalesReturnForm';
import SalesReturnDetail from './pages/salesReturns/SalesReturnDetail';
import PaymentsList from './pages/payments/PaymentsList';
import ReceiptsList from './pages/receipts/ReceiptsList';
import CommissionList from './pages/commission/CommissionList';
import TransfersList from './pages/transfers/TransfersList';
import TransferForm from './pages/transfers/TransferForm';
import TransferDetail from './pages/transfers/TransferDetail';
import LocationsList from './pages/locations/LocationsList';
import ProductionList from './pages/production/ProductionList';
import ProductionOrderDetail from './pages/production/ProductionOrderDetail';
import RentalsList from './pages/rentals/RentalsList';
import RentalForm from './pages/rentals/RentalForm';
import RentalDetail from './pages/rentals/RentalDetail';
import ReportsPage from './pages/reports/ReportsPage';
import ActivityMonitoringPage from './pages/activity/ActivityMonitoringPage';
import BackupDashboardPage from './pages/activity/BackupDashboardPage';
import BarcodeLabelsPage from './pages/products/BarcodeLabelsPage';
import PurchaseOrdersList from './pages/purchaseOrders/PurchaseOrdersList';
import PurchaseOrderForm from './pages/purchaseOrders/PurchaseOrderForm';
import PurchaseOrderReorderPage from './pages/purchaseOrders/PurchaseOrderReorderPage';
import PurchaseOrderDetail from './pages/purchaseOrders/PurchaseOrderDetail';
import AdvanceOrdersList from './pages/advanceOrders/AdvanceOrdersList';
import AdvanceOrderForm from './pages/advanceOrders/AdvanceOrderForm';
import AdvanceOrderDetail from './pages/advanceOrders/AdvanceOrderDetail';
import BackordersList from './pages/backorders/BackordersList';
import BackorderForm from './pages/backorders/BackorderForm';
import BackorderDetail from './pages/backorders/BackorderDetail';
import PurchasesList from './pages/purchases/PurchasesList';
import PurchaseForm from './pages/purchases/PurchaseForm';
import PurchaseDetail from './pages/purchases/PurchaseDetail';
import InventoryList from './pages/inventory/InventoryList';
import DamageEntriesPage from './pages/inventory/DamageEntriesPage';
import BatchesPage from './pages/inventory/BatchesPage';
import PurchaseReturnsList from './pages/purchaseReturns/PurchaseReturnsList';
import PurchaseReturnForm from './pages/purchaseReturns/PurchaseReturnForm';
import PurchaseReturnDetail from './pages/purchaseReturns/PurchaseReturnDetail';
import StockVerificationsList from './pages/stockVerifications/StockVerificationsList';
import StockVerificationForm from './pages/stockVerifications/StockVerificationForm';
import StockVerificationDetail from './pages/stockVerifications/StockVerificationDetail';

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route element={<ProtectedRoute />}>
        <Route element={<AppShell />}>
          <Route index element={<PermissionRoute permission="reports.view"><Dashboard /></PermissionRoute>} />
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
          <Route path="settings" element={<SettingsPage />} />
          <Route path="soon/:module" element={<ComingSoon />} />
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
