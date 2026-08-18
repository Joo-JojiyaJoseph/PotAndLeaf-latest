<?php

namespace Database\Seeders;

use App\Actions\Purchases\ConfirmPurchase;
use App\Actions\Purchases\CreatePurchase;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ReceiptService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Seeds a small but complete slice of live activity for the first company so
 * that reports, payables/receivables, commission and per-location stock all show
 * real numbers on first boot. Idempotent: skips if purchases already exist.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->orderBy('id')->first();
        if (! $company) {
            return;
        }
        if (Purchase::forCompany($company->id)->exists()) {
            return; // already has data
        }

        $suppliers = Supplier::forCompany($company->id)->orderBy('id')->take(2)->get();
        $products = Product::forCompany($company->id)->orderBy('id')->take(4)->get();
        $customers = Customer::forCompany($company->id)->orderBy('id')->take(2)->get();
        if ($suppliers->count() < 1 || $products->count() < 2 || $customers->count() < 1) {
            return;
        }

        $admin = User::where('is_super_admin', true)->first() ?? User::first();
        if ($admin) {
            Auth::login($admin); // so created_by stamps (drives commission attribution)
        }

        $createPurchase = app(CreatePurchase::class);
        $confirmPurchase = app(ConfirmPurchase::class);
        $createSale = app(CreateSale::class);
        $confirmSale = app(ConfirmSale::class);

        // --- Purchases (stock in, supplier outstanding, location balances) ---
        $p1 = $createPurchase->handle($company->id, [
            'supplier_id'    => $suppliers[0]->id,
            'purchase_date'  => now()->subDays(20)->toDateString(),
            'invoice_no'     => 'DEMO-INV-1',
            'is_interstate'  => false,
            'items' => [
                ['product_id' => $products[0]->id, 'qty' => 100, 'rate' => 40, 'gst_rate' => 18],
                ['product_id' => $products[1]->id, 'qty' => 60,  'rate' => 90, 'gst_rate' => 18],
            ],
        ], $admin?->id);
        $confirmPurchase->handle($p1, $admin?->id);

        if ($products->count() >= 4) {
            $p2 = $createPurchase->handle($company->id, [
                'supplier_id'   => ($suppliers[1] ?? $suppliers[0])->id,
                'purchase_date' => now()->subDays(14)->toDateString(),
                'invoice_no'    => 'DEMO-INV-2',
                'is_interstate' => false,
                'items' => [
                    ['product_id' => $products[2]->id, 'qty' => 80, 'rate' => 25, 'gst_rate' => 12],
                    ['product_id' => $products[3]->id, 'qty' => 40, 'rate' => 120, 'gst_rate' => 18],
                ],
            ], $admin?->id);
            $confirmPurchase->handle($p2, $admin?->id);
        }

        // --- Sales (stock out, receivables on credit, loyalty, commission) ---
        $s1 = $createSale->handle($company->id, [
            'customer_id'   => $customers[0]->id,
            'sale_date'     => now()->subDays(9)->toDateString(),
            'payment_mode'  => 'cash',
            'is_interstate' => false,
            'items' => [['product_id' => $products[0]->id, 'qty' => 12, 'rate' => 70, 'gst_rate' => 18]],
        ], $admin?->id);
        $confirmSale->handle($s1, $admin?->id);

        $s2 = $createSale->handle($company->id, [
            'customer_id'   => $customers[0]->id,
            'sale_date'     => now()->subDays(4)->toDateString(),
            'payment_mode'  => 'credit',
            'amount_paid'   => 0,
            'is_interstate' => false,
            'items' => [['product_id' => $products[1]->id, 'qty' => 8, 'rate' => 150, 'gst_rate' => 18]],
        ], $admin?->id);
        $confirmSale->handle($s2, $admin?->id);

        $s3 = $createSale->handle($company->id, [
            'customer_id'   => ($customers[1] ?? $customers[0])->id,
            'sale_date'     => now()->subDays(2)->toDateString(),
            'payment_mode'  => 'upi',
            'is_interstate' => false,
            'items' => [['product_id' => $products[0]->id, 'qty' => 5, 'rate' => 72, 'gst_rate' => 18]],
        ], $admin?->id);
        $confirmSale->handle($s3, $admin?->id);

        // --- One supplier payment + one customer receipt (partial) ---
        app(PaymentService::class)->record($company->id, [
            'supplier_id'  => $suppliers[0]->id,
            'purchase_id'  => $p1->id,
            'payment_date' => now()->subDays(3)->toDateString(),
            'amount'       => 2500,
            'mode'         => 'bank',
            'reference'    => 'DEMO-UTR-1',
        ]);
        app(ReceiptService::class)->record($company->id, [
            'customer_id'  => $customers[0]->id,
            'sale_id'      => $s2->id,
            'receipt_date' => now()->subDay()->toDateString(),
            'amount'       => 500,
            'mode'         => 'upi',
        ]);

        // --- Production: a BOM + one completed run (needs the 3rd/4th products) ---
        if ($products->count() >= 4) {
            $production = app(\App\Services\ProductionService::class);
            $bom = $production->upsertBom($company->id, [
                'product_id' => $products[0]->id,
                'name'       => 'Demo recipe: '.$products[0]->name,
                'output_qty' => 1,
                'is_active'  => true,
                'items'      => [
                    ['component_product_id' => $products[2]->id, 'qty' => 1],
                    ['component_product_id' => $products[3]->id, 'qty' => 0.5],
                ],
            ]);
            $order = $production->createOrder($company->id, [
                'bom_id'          => $bom->id,
                'output_quantity' => 10,
                'order_date'      => now()->subDays(6)->toDateString(),
            ], $admin?->id);
            $production->complete($order, $admin?->id);
        }

        // --- Commission rule for the biller ---
        if ($admin) {
            CommissionRule::updateOrCreate(
                ['company_id' => $company->id, 'user_id' => $admin->id],
                ['base_percent' => 2, 'monthly_target' => 10000, 'target_bonus' => 500, 'is_active' => true],
            );
        }

        // --- Plant rental: one active rental + an invoice ---
        $rentalSvc = app(\App\Services\RentalService::class);
        $rental = $rentalSvc->create($company->id, [
            'customer_id'   => $customers[0]->id,
            'start_date'    => now()->subDays(15)->toDateString(),
            'billing_cycle' => 'monthly',
            'deposit'       => 1000,
            'items'         => [['product_id' => $products[0]->id, 'qty' => 5, 'rate_per_cycle' => 200]],
        ], $admin?->id);
        $rentalSvc->activate($rental, $admin?->id);
        $rentalSvc->generateInvoice($company->id, $rental, [
            'period_from' => now()->subDays(15)->toDateString(),
            'period_to'   => now()->toDateString(),
        ], $admin?->id);

        // --- Purchase order (draft) + advance order (booked) for demo ---
        if ($suppliers->count() >= 1 && $products->count() >= 1) {
            app(\App\Services\PurchaseOrderService::class)->create($company->id, [
                'supplier_id'   => $suppliers[0]->id,
                'po_date'       => now()->subDays(3)->toDateString(),
                'expected_date' => now()->addDays(4)->toDateString(),
                'items'         => [[
                    'product_id' => $products[0]->id, 'qty' => 20,
                    'rate' => (float) $products[0]->cost_price, 'gst_rate' => (float) $products[0]->gst_rate,
                ]],
            ], $admin?->id);
        }
        if ($customers->count() >= 1 && $products->count() >= 2) {
            app(\App\Services\AdvanceOrderService::class)->create($company->id, [
                'customer_id'    => $customers[0]->id,
                'order_date'     => now()->subDays(2)->toDateString(),
                'expected_date'  => now()->addDays(10)->toDateString(),
                'advance_amount' => 500,
                'items'          => [[
                    'product_id' => $products[1]->id, 'qty' => 10,
                    'rate' => (float) $products[1]->retail_price, 'gst_rate' => (float) $products[1]->gst_rate,
                ]],
            ], $admin?->id);
        }

        Auth::logout();
    }
}
