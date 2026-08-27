<?php

namespace Database\Seeders;

use App\Models\CommissionDailyTargetTier;
use App\Models\CommissionPayout;
use App\Models\CommissionPromotion;
use App\Models\CommissionRule;
use App\Models\CommissionTier;
use App\Models\CommissionTransaction;
use App\Models\Backorder;
use App\Models\BackorderItem;
use App\Models\BulkSplit;
use App\Models\BulkSplitItem;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DamageEntry;
use App\Models\EodManagementLog;
use App\Models\LoyaltyLedgerEntry;
use App\Models\LoyaltyRule;
use App\Models\LocationStock;
use App\Models\ManagerCommissionRule;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\StockVerification;
use App\Models\StockVerificationItem;
use App\Models\RentalInvoice;
use App\Models\RentalNotificationLog;
use App\Models\SeasonalCareRule;
use App\Models\SeasonalCareSend;
use App\Models\User;
use App\Models\WhatsAppMessageLog;
use App\Models\WhatsAppTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupplementalDataSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->each(function (Company $company): void {
            $this->seedCompanyData($company);
        });
    }

    private function seedCompanyData(Company $company): void
    {
        $user = User::query()->whereHas('companies', fn ($query) => $query->whereKey($company->id))->first()
            ?? User::query()->first();
        $products = Product::query()->where('company_id', $company->id)->orderBy('id')->get();
        $customer = Customer::query()->where('company_id', $company->id)->orderBy('id')->first();
        $location = DB::table('locations')->where('company_id', $company->id)->orderByDesc('is_default')->first();
        $supplierId = DB::table('suppliers')->where('company_id', $company->id)->value('id');
        if (! $user || $products->isEmpty() || ! $customer || ! $location) {
            return;
        }

        $now = now();
        $product = $products->first();
        $categoryId = $product->category_id;

        foreach ([
            'loyalty_earn_rupees' => '100',
            'loyalty_earn_points' => '1',
            'default_credit_days' => '30',
            'invoice_footer' => 'Thank you for growing with Pot & Leaf.',
        ] as $key => $value) {
            CompanySetting::updateOrCreate(['company_id' => $company->id, 'key' => $key], ['value' => $value]);
        }

        if ($supplierId) {
            foreach ($products->take(4) as $catalogProduct) {
                DB::table('product_supplier')->updateOrInsert(
                    ['product_id' => $catalogProduct->id, 'supplier_id' => $supplierId],
                    ['supplier_price' => $catalogProduct->cost_price, 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        foreach (DB::table('locations')->where('company_id', $company->id)->get() as $companyLocation) {
            foreach ($products->take(6) as $stockProduct) {
                LocationStock::updateOrCreate(
                    ['location_id' => $companyLocation->id, 'product_id' => $stockProduct->id],
                    ['company_id' => $company->id, 'qty' => $companyLocation->id === $location->id ? 20 : 0],
                );
            }
        }

        $rule = CommissionRule::updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $user->id],
            ['base_percent' => 2, 'monthly_target' => 25000, 'target_bonus' => 1000, 'is_active' => true, 'is_supervisor' => false],
        );
        CommissionTier::updateOrCreate(
            ['commission_rule_id' => $rule->id, 'sort_order' => 1],
            ['min_amount' => 0, 'max_amount' => 25000, 'percent' => 2],
        );
        CommissionTier::updateOrCreate(
            ['commission_rule_id' => $rule->id, 'sort_order' => 2],
            ['min_amount' => 25000, 'max_amount' => null, 'percent' => 3],
        );
        CommissionDailyTargetTier::updateOrCreate(
            ['commission_rule_id' => $rule->id, 'sort_order' => 1],
            ['min_amount' => 5000, 'bonus_amount' => 250],
        );
        if ($location) {
            ManagerCommissionRule::updateOrCreate(
                ['company_id' => $company->id, 'user_id' => $user->id, 'location_id' => $location->id],
                ['percent' => 1, 'effective_from' => now()->startOfMonth()->toDateString(), 'is_active' => true],
            );
        }
        CommissionPromotion::updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Monsoon Planting Bonus'],
            ['product_id' => $product->id, 'category_id' => $categoryId, 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->addMonths(2)->toDateString(), 'min_qty' => 2, 'bonus_per_unit' => 10, 'is_active' => true],
        );
        CommissionTransaction::updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $user->id, 'commission_type' => 'sales', 'source_type' => 'seed', 'source_id' => 'DEMO-'.$company->id],
            ['product_id' => $product->id, 'calculation_base' => 5000, 'rate_percent' => 2, 'amount' => 100, 'transaction_date' => now()->toDateString(), 'status' => 'accrued'],
        );
        CommissionPayout::updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $user->id, 'period' => now()->subMonth()->format('Y-m')],
            ['sales_total' => 5000, 'amount' => 100, 'mode' => 'bank', 'payment_date' => now()->subMonth()->endOfMonth()->toDateString(), 'reference' => 'SEED-PAYOUT-'.$company->id, 'status' => 'paid'],
        );

        $loyaltyRule = LoyaltyRule::updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Standard purchase points'],
            ['rule_type' => 'spend', 'earn_rupees' => 100, 'earn_points' => 1, 'min_purchase' => 100, 'priority' => 1, 'is_active' => true],
        );
        LoyaltyLedgerEntry::updateOrCreate(
            ['company_id' => $company->id, 'customer_id' => $customer->id, 'reference_type' => 'seed', 'reference_id' => $customer->id],
            ['type' => 'earn', 'points' => 25, 'balance_after' => 25, 'note' => 'Welcome points', 'rule_snapshot' => ['rule_id' => $loyaltyRule->id]],
        );

        $template = WhatsAppTemplate::updateOrCreate(
            ['company_id' => $company->id, 'slug' => 'care-follow-up'],
            ['name' => 'Plant care follow-up', 'body' => 'Hello {{customer}}, how is your plant doing? Reply for care tips.', 'is_active' => true],
        );
        WhatsAppMessageLog::updateOrCreate(
            ['company_id' => $company->id, 'recipient_phone' => $customer->phone, 'message_type' => 'care-follow-up', 'business_date' => now()->toDateString()],
            ['recipient_type' => 'customer', 'recipient_id' => $customer->id, 'message' => $template->body, 'status' => 'pending', 'retry_count' => 0],
        );

        $careRule = SeasonalCareRule::updateOrCreate(
            ['company_id' => $company->id, 'name' => 'New plant care reminder'],
            ['product_id' => $product->id, 'days_after_purchase' => 15, 'season_months' => [6, 7, 8], 'message_template' => 'Remember to water and feed your new plant.', 'max_sends_per_customer' => 1, 'is_active' => true],
        );
        $sale = DB::table('sales')->where('company_id', $company->id)->where('customer_id', $customer->id)->first();
        if ($sale) {
            SeasonalCareSend::firstOrCreate(['seasonal_care_rule_id' => $careRule->id, 'customer_id' => $customer->id, 'sale_id' => $sale->id], ['sent_at' => $now->copy()->subDay()]);
        }

        EodManagementLog::updateOrCreate(
            ['company_id' => $company->id, 'channel' => 'email', 'recipient' => 'manager@potandleaf.test', 'business_date' => now()->subDay()->toDateString()],
            ['status' => 'sent', 'sent_at' => $now->copy()->subDay()],
        );
        ActivityLog::updateOrCreate(
            ['company_id' => $company->id, 'action' => 'seeded', 'module' => 'system', 'entity_type' => 'company', 'entity_id' => null],
            ['user_id' => $user->id, 'description' => 'Demo reference data initialized', 'meta' => ['source' => 'SupplementalDataSeeder']],
        );

        $rentalInvoice = RentalInvoice::query()->whereHas('rental', fn ($query) => $query->where('company_id', $company->id))->first();
        if ($rentalInvoice) {
            RentalNotificationLog::updateOrCreate(
                ['company_id' => $company->id, 'rental_invoice_id' => $rentalInvoice->id, 'event' => 'invoice_created'],
                ['rental_id' => $rentalInvoice->rental_id, 'channel' => 'whatsapp', 'recipient' => $customer->phone ?? 'customer', 'status' => 'sent', 'message' => 'Your plant rental invoice is ready.', 'sent_at' => $now->copy()->subDay()],
            );
        }

        $this->seedInventoryDocuments($company, $user, $customer, $product, $location);
    }

    private function seedInventoryDocuments(Company $company, User $user, Customer $customer, Product $product, object $location): void
    {
        $verification = StockVerification::updateOrCreate(
            ['company_id' => $company->id, 'count_no' => 'COUNT-SEED-'.$company->id],
            ['count_date' => now()->subDay()->toDateString(), 'location_note' => $location->name, 'status' => 'submitted', 'notes' => 'Demo physical count awaiting approval', 'submitted_at' => now()->subDay(), 'created_by' => $user->id],
        );
        StockVerificationItem::updateOrCreate(
            ['stock_verification_id' => $verification->id, 'product_id' => $product->id],
            ['product_name' => $product->name, 'system_qty' => 20, 'counted_qty' => 19, 'variance' => -1, 'unit_cost' => $product->cost_price],
        );

        DamageEntry::updateOrCreate(
            ['company_id' => $company->id, 'entry_no' => 'DMG-SEED-'.$company->id],
            ['product_id' => $product->id, 'location_id' => $location->id, 'entry_date' => now()->subDays(3)->toDateString(), 'qty' => 1, 'reason' => 'Wilted plant', 'notes' => 'Removed during routine quality check', 'created_by' => $user->id],
        );

        $secondLocation = DB::table('locations')->where('company_id', $company->id)->where('id', '!=', $location->id)->first();
        if ($secondLocation) {
            $transfer = StockTransfer::updateOrCreate(
                ['company_id' => $company->id, 'transfer_no' => 'TRF-SEED-'.$company->id],
                ['from_location_id' => $location->id, 'to_location_id' => $secondLocation->id, 'transfer_type' => 'internal', 'transfer_date' => now()->subDays(2)->toDateString(), 'status' => 'draft', 'notes' => 'Demo replenishment request', 'created_by' => $user->id],
            );
            StockTransferItem::updateOrCreate(
                ['stock_transfer_id' => $transfer->id, 'product_id' => $product->id],
                ['product_name' => $product->name, 'qty' => 5, 'received_qty' => 0],
            );
        }

        $backorder = Backorder::updateOrCreate(
            ['company_id' => $company->id, 'order_no' => 'BO-SEED-'.$company->id],
            ['customer_id' => $customer->id, 'location_id' => $location->id, 'order_date' => now()->subDays(2)->toDateString(), 'expected_date' => now()->addDays(7)->toDateString(), 'status' => 'open', 'notes' => 'Demo shortage order', 'created_by' => $user->id],
        );
        BackorderItem::updateOrCreate(
            ['backorder_id' => $backorder->id, 'product_id' => $product->id],
            ['product_name' => $product->name, 'ordered_qty' => 5, 'fulfilled_qty' => 0, 'cancelled_qty' => 0, 'rate' => $product->retail_price],
        );

        $split = BulkSplit::updateOrCreate(
            ['company_id' => $company->id, 'split_no' => 'SPLIT-SEED-'.$company->id],
            ['source_product_id' => $product->id, 'source_product_name' => $product->name, 'split_date' => now()->subDays(4)->toDateString(), 'source_qty' => 10, 'source_unit_cost' => $product->cost_price, 'total_cost' => $product->cost_price * 10, 'split_mode' => 'quantity', 'split_total_qty' => 10, 'status' => 'draft', 'notes' => 'Demo bulk split awaiting confirmation', 'created_by' => $user->id],
        );
        BulkSplitItem::updateOrCreate(
            ['bulk_split_id' => $split->id, 'product_id' => $product->id],
            ['product_name' => $product->name, 'qty' => 10, 'weight' => 1, 'cost_alloc' => $product->cost_price * 10, 'unit_cost' => $product->cost_price],
        );

        $purchase = DB::table('purchases')->where('company_id', $company->id)->first();
        $supplierId = DB::table('suppliers')->where('company_id', $company->id)->value('id');
        if ($purchase && $supplierId) {
            $purchaseReturn = PurchaseReturn::updateOrCreate(
                ['company_id' => $company->id, 'return_no' => 'PR-SEED-'.$company->id],
                ['purchase_id' => $purchase->id, 'supplier_id' => $supplierId, 'return_date' => now()->subDay()->toDateString(), 'reason' => 'Damaged on receipt', 'status' => 'draft'],
            );
            PurchaseReturnItem::updateOrCreate(
                ['purchase_return_id' => $purchaseReturn->id, 'product_id' => $product->id],
                ['product_name' => $product->name, 'qty' => 1, 'rate' => $product->cost_price, 'gst_rate' => $product->gst_rate, 'taxable_value' => $product->cost_price, 'line_total' => $product->cost_price, 'unit_cost' => $product->cost_price],
            );
        }

        $sale = DB::table('sales')->where('company_id', $company->id)->first();
        if ($sale) {
            $salesReturn = SalesReturn::updateOrCreate(
                ['company_id' => $company->id, 'return_no' => 'SR-SEED-'.$company->id],
                ['sale_id' => $sale->id, 'customer_id' => $customer->id, 'location_id' => $location->id, 'return_date' => now()->subDay()->toDateString(), 'reason' => 'Customer exchange request', 'status' => 'draft'],
            );
            SalesReturnItem::updateOrCreate(
                ['sales_return_id' => $salesReturn->id, 'product_id' => $product->id],
                ['product_name' => $product->name, 'qty' => 1, 'rate' => $product->retail_price, 'discount' => 0, 'gst_rate' => $product->gst_rate, 'taxable_value' => $product->retail_price, 'line_total' => $product->retail_price, 'unit_cost' => $product->cost_price],
            );
        }
    }
}
