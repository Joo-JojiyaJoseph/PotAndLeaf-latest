<?php

use App\Http\Controllers\Api\ActivityMonitoringController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\DamageEntryController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\BulkSplitController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\CustomerReceiptController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ProductionController;
use App\Http\Controllers\Api\RentalController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\LoyaltyController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\AdvanceOrderController;
use App\Http\Controllers\Api\BackorderController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchaseReturnController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SalesReturnController;
use App\Http\Controllers\Api\InvoicePdfController;
use App\Http\Controllers\Api\SupplierPaymentController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\StockVerificationController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Middleware\ResolveApiCompany;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (decoupled React SPA — Sanctum token auth)
|--------------------------------------------------------------------------
| Register in bootstrap/app.php:
|   ->withRouting(
|       web: __DIR__.'/../routes/web.php',
|       api: __DIR__.'/../routes/api.php',
|       commands: __DIR__.'/../routes/console.php',
|   )
| Company-scoped routes require an "X-Company-Id" header (see ResolveApiCompany).
*/

// Public
Route::post('login', [AuthController::class, 'login']);

// Authenticated (any company)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::put('me', [AuthController::class, 'updateProfile']);
    Route::post('uploads', [UploadController::class, 'store']);
    Route::post('logout', [AuthController::class, 'logout']);

    // Company management (HO super admin only — not company-scoped)
    Route::get('companies', [CompanyController::class, 'index']);
    Route::patch('companies/{company}/status', [CompanyController::class, 'toggleStatus']);
    Route::post('companies', [CompanyController::class, 'store']);
    Route::get('companies/{company}', [CompanyController::class, 'show']);
    Route::put('companies/{company}', [CompanyController::class, 'update']);
    Route::delete('companies/{company}', [CompanyController::class, 'destroy']);

    // Company-scoped
    Route::middleware(ResolveApiCompany::class)->group(function () {
        Route::get('permissions', [AuthController::class, 'permissions']);
        Route::get('dashboard', [DashboardController::class, 'index']);

        Route::get('suppliers', [SupplierController::class, 'index']);
        Route::post('suppliers', [SupplierController::class, 'store']);
        Route::get('suppliers/{supplier}/purchase-history', [SupplierController::class, 'purchaseHistory']);
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show']);
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy']);

        Route::get('settings', [SettingsController::class, 'show']);
        Route::put('settings', [SettingsController::class, 'update']);

        // Module 07 — Commission
        Route::get('commission/form-data', [CommissionController::class, 'formData']);
        Route::get('commission/rules', [CommissionController::class, 'rules']);
        Route::post('commission/rules', [CommissionController::class, 'upsertRule']);
        Route::get('commission/compute', [CommissionController::class, 'compute']);
        Route::get('commission/supervisor-entries', [CommissionController::class, 'supervisorEntries']);
        Route::get('commission/payouts', [CommissionController::class, 'payouts']);
        Route::post('commission/payouts', [CommissionController::class, 'storePayout']);
        Route::delete('commission/payouts/{commissionPayout}', [CommissionController::class, 'destroyPayout']);

        // Module 08 — Customer receipts
        Route::get('customer-receipts/form-data', [CustomerReceiptController::class, 'formData']);
        Route::get('customer-receipts/receivables', [CustomerReceiptController::class, 'receivables']);
        Route::get('customer-receipts', [CustomerReceiptController::class, 'index']);
        Route::post('customer-receipts', [CustomerReceiptController::class, 'store']);
        Route::delete('customer-receipts/{customerReceipt}', [CustomerReceiptController::class, 'destroy']);

        // Module 08 — Supplier payments
        Route::get('supplier-payments/form-data', [SupplierPaymentController::class, 'formData']);
        Route::get('supplier-payments/payables', [SupplierPaymentController::class, 'payables']);
        Route::get('supplier-payments', [SupplierPaymentController::class, 'index']);
        Route::post('supplier-payments', [SupplierPaymentController::class, 'store']);
        Route::delete('supplier-payments/{supplierPayment}', [SupplierPaymentController::class, 'destroy']);

        // Module 03 — Sales / POS
        Route::get('sales/form-data', [SaleController::class, 'formData']);
        Route::get('sales', [SaleController::class, 'index']);
        Route::post('sales', [SaleController::class, 'store']);
        Route::get('sales/{sale}/invoice.pdf', [InvoicePdfController::class, 'sale']);
        Route::get('sales/{sale}', [SaleController::class, 'show']);
        Route::post('sales/{sale}/confirm', [SaleController::class, 'confirm']);
        Route::post('sales/{sale}/cancel-request', [SaleController::class, 'requestCancellation']);
        Route::post('sales/{sale}/cancel-approve', [SaleController::class, 'approveCancellation']);
        Route::post('sales/{sale}/cancel-reject', [SaleController::class, 'rejectCancellation']);
        Route::post('sales/{sale}/convert-proforma', [SaleController::class, 'convertProforma']);
        Route::post('sales/{sale}/whatsapp', [SaleController::class, 'sendWhatsapp']);
        Route::delete('sales/{sale}', [SaleController::class, 'destroy']);

        Route::get('sales-returns/source', [SalesReturnController::class, 'source']);
        Route::get('sales-returns', [SalesReturnController::class, 'index']);
        Route::post('sales-returns', [SalesReturnController::class, 'store']);
        Route::get('sales-returns/{salesReturn}', [SalesReturnController::class, 'show']);
        Route::post('sales-returns/{salesReturn}/confirm', [SalesReturnController::class, 'confirm']);
        Route::delete('sales-returns/{salesReturn}', [SalesReturnController::class, 'destroy']);

        // Module 12 — Customer master (CRUD)
        Route::get('customers', [CustomerController::class, 'index']);
        Route::get('loyalty', [LoyaltyController::class, 'index']);
        Route::post('customers', [CustomerController::class, 'store']);
        Route::get('customers/{customer}/purchase-history', [CustomerController::class, 'purchaseHistory']);
        Route::get('customers/{customer}', [CustomerController::class, 'show']);
        Route::put('customers/{customer}', [CustomerController::class, 'update']);
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy']);

        // Products master (CRUD)
        Route::get('products/form-data', [ProductController::class, 'formData']);
        Route::get('products', [ProductController::class, 'index']);
        Route::patch('products/{product}/status', [ProductController::class, 'toggleStatus']);
        Route::patch('users/{user}/status', [UserController::class, 'toggleStatus']);
        Route::patch('customers/{customer}/status', [CustomerController::class, 'toggleStatus']);
        Route::patch('suppliers/{supplier}/status', [SupplierController::class, 'toggleStatus']);

        Route::post('products', [ProductController::class, 'store']);
        Route::get('products/{product}', [ProductController::class, 'show']);
        Route::get('products/{product}/batches', [ProductController::class, 'batches']);
        Route::get('batches/scan', [ProductController::class, 'scanBatch']);
        Route::get('inventory/batches', [ProductController::class, 'batchesOverview']);
        Route::post('batches/generate-opening', [ProductController::class, 'generateOpeningBatches']);
        Route::put('products/{product}', [ProductController::class, 'update']);
        Route::delete('products/{product}', [ProductController::class, 'destroy']);

        // Milestone 2 — Procurement
        Route::get('purchases/form-data', [PurchaseController::class, 'formData']);
        Route::get('purchases', [PurchaseController::class, 'index']);
        Route::post('purchases', [PurchaseController::class, 'store']);
        Route::get('purchases/{purchase}/invoice.pdf', [InvoicePdfController::class, 'purchase']);
        Route::get('purchases/{purchase}', [PurchaseController::class, 'show']);
        Route::put('purchases/{purchase}', [PurchaseController::class, 'update']);
        Route::post('purchases/{purchase}/confirm', [PurchaseController::class, 'confirm']);
        Route::get('purchases/{purchase}/batches', [PurchaseController::class, 'batches']);
        Route::delete('purchases/{purchase}', [PurchaseController::class, 'destroy']);

        // Milestone 2 — Inventory
        // Module 06 — Plant rental
        Route::get('rentals/form-data', [RentalController::class, 'formData']);
        Route::get('rentals', [RentalController::class, 'index']);
        Route::post('rentals', [RentalController::class, 'store']);
        Route::get('rentals/{rental}', [RentalController::class, 'show']);
        Route::post('rentals/{rental}/activate', [RentalController::class, 'activate']);
        Route::post('rentals/{rental}/return', [RentalController::class, 'returnItems']);
        Route::post('rentals/{rental}/settle', [RentalController::class, 'settle']);
        Route::post('rentals/{rental}/invoices', [RentalController::class, 'generateInvoice']);
        Route::delete('rentals/{rental}', [RentalController::class, 'destroy']);
        Route::post('rental-invoices/{rentalInvoice}/paid', [RentalController::class, 'markInvoicePaid']);
        Route::get('rental-invoices/{rentalInvoice}/invoice.pdf', [InvoicePdfController::class, 'rental']);
        Route::post('rental-invoices/{rentalInvoice}/whatsapp', [RentalController::class, 'sendInvoiceWhatsapp']);
        Route::delete('rental-invoices/{rentalInvoice}', [RentalController::class, 'deleteInvoice']);

        // Module 10 — Advance orders (customer pre-bookings)
        Route::get('advance-orders/form-data', [AdvanceOrderController::class, 'formData']);
        Route::get('advance-orders', [AdvanceOrderController::class, 'index']);
        Route::post('advance-orders', [AdvanceOrderController::class, 'store']);
        Route::get('advance-orders/{advanceOrder}', [AdvanceOrderController::class, 'show']);
        Route::post('advance-orders/{advanceOrder}/fulfill', [AdvanceOrderController::class, 'fulfill']);
        Route::delete('advance-orders/{advanceOrder}', [AdvanceOrderController::class, 'destroy']);

        // Module 10b — Backorders (shortage when stock unavailable)
        Route::get('backorders/form-data', [BackorderController::class, 'formData']);
        Route::get('backorders', [BackorderController::class, 'index']);
        Route::post('backorders', [BackorderController::class, 'store']);
        Route::get('backorders/{backorder}', [BackorderController::class, 'show']);
        Route::post('backorders/{backorder}/fulfill', [BackorderController::class, 'fulfill']);
        Route::delete('backorders/{backorder}', [BackorderController::class, 'destroy']);
        Route::post('sales/{sale}/backorder', [BackorderController::class, 'createFromSale']);

        // Module 09 — Purchase orders / reorder
        Route::get('purchase-orders/form-data', [PurchaseOrderController::class, 'formData']);
        Route::get('purchase-orders/suggestions', [PurchaseOrderController::class, 'suggestions']);
        Route::get('purchase-orders/reorder-report', [PurchaseOrderController::class, 'reorderReport']);
        Route::post('purchase-orders/batch-from-reorder', [PurchaseOrderController::class, 'batchFromReorder']);
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
        Route::post('purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'send']);
        Route::post('purchase-orders/{purchaseOrder}/convert', [PurchaseOrderController::class, 'convert']);
        Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy']);

        // Product master data (categories / brands / units)
        Route::get('masters/{type}', [MasterDataController::class, 'index'])->whereIn('type', ['categories', 'brands', 'units']);
        Route::post('masters/{type}', [MasterDataController::class, 'store'])->whereIn('type', ['categories', 'brands', 'units']);
        Route::put('masters/{type}/{id}', [MasterDataController::class, 'update'])->whereIn('type', ['categories', 'brands', 'units']);
        Route::delete('masters/{type}/{id}', [MasterDataController::class, 'destroy'])->whereIn('type', ['categories', 'brands', 'units']);

        // Module 11 — Reports
        Route::get('reports/form-data', [ReportController::class, 'formData']);
        Route::get('reports/dashboard', [ReportController::class, 'dashboard']);
        Route::get('reports/dashboard/export', [ReportController::class, 'exportDashboard']);
        Route::get('reports/margin', [ReportController::class, 'margin']);
        Route::get('reports/margin/export', [ReportController::class, 'exportMargin']);
        Route::get('reports/profit', [ReportController::class, 'profit']);
        Route::get('reports/profit/export', [ReportController::class, 'exportProfit']);
        Route::get('reports/price-levels', [ReportController::class, 'priceLevels']);
        Route::get('reports/rental/delivery', [ReportController::class, 'rentalDelivery']);
        Route::get('reports/rental/delivery/export', [ReportController::class, 'exportRentalDelivery']);
        Route::get('reports/rental/income', [ReportController::class, 'rentalIncome']);
        Route::get('reports/rental/income/export', [ReportController::class, 'exportRentalIncome']);
        Route::get('reports/rental/current', [ReportController::class, 'rentalCurrent']);
        Route::get('reports/rental/current/export', [ReportController::class, 'exportRentalCurrent']);
        Route::get('reports/rental/customer/{customer}', [ReportController::class, 'rentalCustomer']);
        Route::get('reports/rental/customer/{customer}/export', [ReportController::class, 'exportRentalCustomer']);
        Route::get('reports/production/summary', [ReportController::class, 'productionSummary']);
        Route::get('reports/production/by-product', [ReportController::class, 'productionByProduct']);
        Route::get('reports/production/by-supervisor', [ReportController::class, 'productionBySupervisor']);
        Route::get('reports/production/batches', [ReportController::class, 'productionBatches']);
        Route::get('reports/transfers/summary', [ReportController::class, 'transferSummary']);
        Route::get('reports/transfers/in-transit', [ReportController::class, 'transferInTransit']);
        Route::get('reports/accounting/cash-book', [ReportController::class, 'cashBook']);
        Route::get('reports/accounting/bank-book', [ReportController::class, 'bankBook']);
        Route::get('reports/accounting/debtor-ledger', [ReportController::class, 'debtorLedger']);
        Route::get('reports/accounting/creditor-ledger', [ReportController::class, 'creditorLedger']);
        Route::get('reports/accounting/ageing-receivables', [ReportController::class, 'ageingReceivables']);
        Route::get('reports/accounting/ageing-payables', [ReportController::class, 'ageingPayables']);

        Route::get('activity-monitoring/form-data', [ActivityMonitoringController::class, 'formData']);
        Route::get('activity-monitoring', [ActivityMonitoringController::class, 'index']);

        Route::get('backups', [BackupController::class, 'index']);
        Route::post('backups/run', [BackupController::class, 'run']);
        Route::get('backups/{filename}/download', [BackupController::class, 'download'])->where('filename', '[\w\-.]+');
        Route::post('backups/{filename}/restore', [BackupController::class, 'restore'])->where('filename', '[\w\-.]+');

        // Module 02 — Production / BOM
        Route::get('production/form-data', [ProductionController::class, 'formData']);
        Route::get('production/estimate', [ProductionController::class, 'estimate']);
        Route::get('production/boms', [ProductionController::class, 'boms']);
        Route::post('production/boms', [ProductionController::class, 'storeBom']);
        Route::put('production/boms/{bom}', [ProductionController::class, 'updateBom']);
        Route::delete('production/boms/{bom}', [ProductionController::class, 'destroyBom']);
        Route::get('production/orders', [ProductionController::class, 'orders']);
        Route::post('production/orders', [ProductionController::class, 'storeOrder']);
        Route::get('production/orders/{productionOrder}', [ProductionController::class, 'showOrder']);
        Route::put('production/orders/{productionOrder}', [ProductionController::class, 'updateOrder']);
        Route::post('production/orders/{productionOrder}/complete', [ProductionController::class, 'complete']);
        Route::post('production/orders/{productionOrder}/stages/{productionOrderStage}/start', [ProductionController::class, 'startStage']);
        Route::post('production/orders/{productionOrder}/stages/{productionOrderStage}/complete', [ProductionController::class, 'completeStage']);
        Route::delete('production/orders/{productionOrder}', [ProductionController::class, 'destroyOrder']);

        // Module 05 — Locations & stock transfers
        Route::get('locations', [LocationController::class, 'index']);
        Route::post('locations', [LocationController::class, 'store']);
        Route::put('locations/{location}', [LocationController::class, 'update']);
        Route::delete('locations/{location}', [LocationController::class, 'destroy']);
        Route::get('transfers/form-data', [TransferController::class, 'formData']);
        Route::get('transfers', [TransferController::class, 'index']);
        Route::post('transfers', [TransferController::class, 'store']);
        Route::get('transfers/{stockTransfer}', [TransferController::class, 'show']);
        Route::post('transfers/{stockTransfer}/dispatch', [TransferController::class, 'dispatchTransfer']);
        Route::post('transfers/{stockTransfer}/approve', [TransferController::class, 'approve']);
        Route::post('transfers/{stockTransfer}/reject', [TransferController::class, 'reject']);
        Route::post('transfers/{stockTransfer}/redirect', [TransferController::class, 'redirect']);
        Route::post('transfers/{stockTransfer}/receive', [TransferController::class, 'receive']);
        Route::delete('transfers/{stockTransfer}', [TransferController::class, 'destroy']);
        Route::get('inventory/by-location', [InventoryController::class, 'byLocation']);

        Route::get('inventory/stock', [InventoryController::class, 'stock']);
        Route::get('inventory/stock/cross-branch', [InventoryController::class, 'crossBranchStock']);
        Route::get('inventory/alerts', [InventoryController::class, 'alerts']);
        Route::get('inventory/ledger/form-data', [InventoryController::class, 'ledgerFormData']);
        Route::get('inventory/ledger', [InventoryController::class, 'ledger']);
        Route::get('inventory/ledger/export', [InventoryController::class, 'exportLedger']);
        Route::get('inventory/valuation', [InventoryController::class, 'valuation']);
        Route::get('inventory/movement', [InventoryController::class, 'movement']);

        Route::get('damage-entries/form-data', [DamageEntryController::class, 'formData']);
        Route::get('damage-entries', [DamageEntryController::class, 'index']);
        Route::post('damage-entries', [DamageEntryController::class, 'store']);

        // Milestone 2 — Purchase returns (debit note + stock reversal)
        Route::get('purchase-returns/source', [PurchaseReturnController::class, 'source']);
        Route::get('purchase-returns', [PurchaseReturnController::class, 'index']);
        Route::post('purchase-returns', [PurchaseReturnController::class, 'store']);
        Route::get('purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'show']);
        Route::post('purchase-returns/{purchaseReturn}/confirm', [PurchaseReturnController::class, 'confirm']);
        Route::delete('purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'destroy']);

        // Module 01 — Bulk splitting (cost redistribution + stock conversion)
        Route::get('bulk-splits/form-data', [BulkSplitController::class, 'formData']);
        Route::get('bulk-splits', [BulkSplitController::class, 'index']);
        Route::post('bulk-splits', [BulkSplitController::class, 'store']);
        Route::get('bulk-splits/{bulkSplit}', [BulkSplitController::class, 'show']);
        Route::post('bulk-splits/{bulkSplit}/confirm', [BulkSplitController::class, 'confirm']);
        Route::delete('bulk-splits/{bulkSplit}', [BulkSplitController::class, 'destroy']);

        // Milestone 2 — Physical stock verification (HO approval workflow)
        Route::get('stock-verifications/form-data', [StockVerificationController::class, 'formData']);
        Route::get('stock-verifications', [StockVerificationController::class, 'index']);
        Route::post('stock-verifications', [StockVerificationController::class, 'store']);
        Route::get('stock-verifications/{stockVerification}', [StockVerificationController::class, 'show']);
        Route::post('stock-verifications/{stockVerification}/submit', [StockVerificationController::class, 'submit']);
        Route::post('stock-verifications/{stockVerification}/approve', [StockVerificationController::class, 'approve']);
        Route::post('stock-verifications/{stockVerification}/reject', [StockVerificationController::class, 'reject']);

        // Module 14 — Access control: users & roles (company-scoped)
        Route::get('users/form-data', [UserController::class, 'formData']);
        Route::get('users', [UserController::class, 'index']);
        Route::get('users/{user}', [UserController::class, 'show']);
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::delete('users/{user}', [UserController::class, 'destroy']);

        Route::get('roles/form-data', [RoleController::class, 'formData']);
        Route::get('roles', [RoleController::class, 'index']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::get('roles/{role}', [RoleController::class, 'show']);
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);
    });
});
