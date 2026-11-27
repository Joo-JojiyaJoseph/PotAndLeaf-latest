<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Sale;
use App\Services\WhatsApp\WhatsAppService;

class SaleNotificationService
{
    public function __construct(
        private readonly WhatsAppService $whatsapp,
        private readonly SettingsService $settings,
    ) {}

    /** @return array{success: bool, message: string, provider?: string} */
    public function sendInvoiceWhatsApp(Sale $sale): array
    {
        $sale->loadMissing(['items', 'customer:id,name,phone,whatsapp', 'company:id,name,legal_name,gst_number,address,phone,state,state_code']);
        $company = $sale->company;

        if (! $company) {
            return ['success' => false, 'message' => 'Sale is missing company details.'];
        }

        if ($this->settings->get($company->id, 'whatsapp_enabled') !== '1') {
            return ['success' => false, 'message' => 'WhatsApp sharing is disabled for this company.'];
        }

        if (! in_array($sale->status, ['confirmed', 'proforma'], true)) {
            return ['success' => false, 'message' => 'Only confirmed or proforma invoices can be shared.'];
        }

        $customer = $sale->customer;
        $to = $customer?->whatsapp ?: $customer?->phone;
        $message = $this->buildInvoiceMessage($company, $sale);
        $result = $this->whatsapp->sendMessage($to, $message);

        if ($result['success']) {
            $sale->update(['notes' => trim(($sale->notes ? $sale->notes."\n" : '').'WhatsApp sent '.now()->format('d M Y H:i'))]);
        }

        return $result;
    }

    /**
     * "How to look after what you bought": lists the products and each category's care guide
     * (Malayalam and English). Sent to the customer's WhatsApp after a sale.
     *
     * @return array{success: bool, message: string, provider?: string}
     */
    public function sendCareGuideWhatsApp(Sale $sale, bool $requireAutoSetting = false): array
    {
        $sale->loadMissing(['items', 'customer:id,name,phone,whatsapp', 'company:id,name']);
        $company = $sale->company;
        if (! $company) {
            return ['success' => false, 'message' => 'Sale is missing company details.'];
        }
        if ($this->settings->get($company->id, 'whatsapp_enabled') !== '1') {
            return ['success' => false, 'message' => 'WhatsApp sharing is disabled for this company.'];
        }
        if ($requireAutoSetting && $this->settings->get($company->id, 'sale_care_whatsapp') !== '1') {
            return ['success' => false, 'message' => 'Care-guide WhatsApp is switched off.'];
        }
        if ($sale->status !== 'confirmed') {
            return ['success' => false, 'message' => 'Only confirmed sales can send a care guide.'];
        }
        $to = $sale->customer?->whatsapp ?: $sale->customer?->phone;
        if (! $to) {
            return ['success' => false, 'message' => 'The customer has no phone number.'];
        }
        $message = $this->buildCareGuideMessage($company, $sale);
        if ($message === null) {
            return ['success' => false, 'message' => 'None of the products bought has a care guide.'];
        }

        return $this->whatsapp->sendMessage($to, $message);
    }

    /** Null when no purchased product's category has a care guide. */
    public function buildCareGuideMessage(Company $company, Sale $sale): ?string
    {
        $productIds = $sale->items->pluck('product_id')->filter()->unique()->all();
        $products = \App\Models\Product::query()->whereIn('id', $productIds)->get(['id', 'name', 'category_id'])->keyBy('id');
        $categories = \App\Models\ProductCategory::query()->whereIn('id', $products->pluck('category_id')->filter()->unique()->all())->get()->keyBy('id');
        $parentIds = $categories->pluck('parent_id')->filter()->unique()->diff($categories->keys())->all();
        if ($parentIds) {
            $categories = $categories->union(\App\Models\ProductCategory::query()->whereIn('id', $parentIds)->get()->keyBy('id'));
        }

        $lines = ["*Thank you for shopping with {$company->name}!*", '', 'What you bought and how to look after it:'];
        $seen = [];
        $any = false;
        foreach ($sale->items as $item) {
            $p = $products->get($item->product_id);
            // The product's own (sub)category guide wins; fall back to its parent category's.
            $cat = $p ? $categories->get($p->category_id) : null;
            if ($cat && ! filled($cat->care_guide_en) && ! filled($cat->care_guide_ml) && $cat->parent_id) {
                $cat = $categories->get($cat->parent_id) ?: $cat;
            }
            $lines[] = '';
            $lines[] = "• *{$item->product_name}*";
            if ($cat && (filled($cat->care_guide_en) || filled($cat->care_guide_ml)) && ! isset($seen[$cat->id])) {
                $seen[$cat->id] = true;
                $any = true;
                if (filled($cat->care_guide_ml)) {
                    $lines[] = trim($cat->care_guide_ml);
                }
                if (filled($cat->care_guide_en)) {
                    $lines[] = trim($cat->care_guide_en);
                }
            } elseif ($cat && isset($seen[$cat->id])) {
                $lines[] = "_(care as for {$cat->name} above)_";
            }
        }

        return $any ? implode("\n", $lines) : null;
    }

    public function buildInvoiceMessage(Company $company, Sale $sale): string
    {
        $money = fn ($n) => '₹'.number_format((float) $n, 2);
        $kind = match ($sale->bill_kind) {
            'proforma'      => 'Proforma',
            'complimentary' => 'Complimentary',
            default         => 'Tax',
        };

        $lines = [
            "*{$kind} Invoice {$sale->sale_no}*",
            "Customer: {$sale->customer_name}",
            'Date: '.optional($sale->sale_date)->format('d M Y'),
            '',
        ];

        foreach ($sale->items as $item) {
            $lines[] = "- {$item->product_name}: {$item->qty} x {$money($item->rate)} = {$money($item->line_total)}";
        }

        $lines[] = '';
        if ((float) $sale->loyalty_discount > 0) {
            $lines[] = "Loyalty discount: −{$money($sale->loyalty_discount)}";
        }
        $payable = max(0, (float) $sale->grand_total - (float) $sale->loyalty_discount);
        $lines[] = "*Total: {$money($payable)}*";
        if ($sale->isProforma()) {
            $lines[] = '_This is a proforma — not a tax invoice until converted._';
        }
        $lines[] = '';
        $lines[] = "Thank you for shopping with {$company->name}.";

        return implode("\n", $lines);
    }
}
