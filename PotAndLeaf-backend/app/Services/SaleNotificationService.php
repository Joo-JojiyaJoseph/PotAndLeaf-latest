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
