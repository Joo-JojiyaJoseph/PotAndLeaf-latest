<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Rental;
use App\Models\RentalInvoice;
use App\Models\RentalNotificationLog;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RentalNotificationService
{
    public function __construct(
        private readonly WhatsAppService $whatsapp,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{success: bool, message: string, provider?: string}
     */
    public function sendInvoiceWhatsApp(RentalInvoice $invoice, bool $requireAutoSetting = true): array
    {
        $invoice->loadMissing(['rental.items', 'rental.customer:id,name,phone,whatsapp', 'rental.company:id,name,legal_name,gst_number,address,phone,state,state_code']);
        $rental = $invoice->rental;
        $company = $rental?->company;

        if (! $company || ! $rental) {
            return ['success' => false, 'message' => 'Rental invoice is missing related data.'];
        }

        if ($this->settings->get($company->id, 'whatsapp_enabled') !== '1') {
            $this->log($company->id, $rental->id, $invoice->id, 'invoice_sent', null, 'skipped', 'WhatsApp disabled for company.');

            return ['success' => false, 'message' => 'WhatsApp sharing is disabled for this company.'];
        }

        if ($requireAutoSetting && $this->settings->get($company->id, 'rental_whatsapp_on_bill') !== '1') {
            $this->log($company->id, $rental->id, $invoice->id, 'invoice_sent', null, 'skipped', 'Auto WhatsApp on bill disabled.');

            return ['success' => false, 'message' => 'Automatic WhatsApp on billing is disabled in settings.'];
        }

        $customer = $rental->customer;
        $to = $customer?->whatsapp ?: $customer?->phone;
        $message = $this->buildInvoiceMessage($company, $rental, $invoice);
        $result = $this->whatsapp->sendMessage($to, $message);

        $this->log(
            $company->id,
            $rental->id,
            $invoice->id,
            'invoice_sent',
            $to,
            $result['success'] ? 'sent' : 'failed',
            $result['message'],
            $message,
        );

        if ($result['success']) {
            $invoice->update(['sent_at' => now()]);
        }

        return $result;
    }

    /** @return array{return_alerts: int, payment_alerts: int} */
    public function sendOverdueAlerts(?int $companyId = null): array
    {
        $returnAlerts = 0;
        $paymentAlerts = 0;
        $today = Carbon::today();

        $rentalQuery = Rental::query()
            ->where('status', 'active')
            ->whereNotNull('expected_end_date')
            ->with(['customer:id,name,phone,whatsapp', 'company:id,name']);

        if ($companyId !== null) {
            $rentalQuery->forCompany($companyId);
        }

        foreach ($rentalQuery->get() as $rental) {
            if ($this->settings->get($rental->company_id, 'whatsapp_enabled') !== '1') {
                continue;
            }

            $graceCutoff = $today->copy()->subDays(max(0, $this->settings->getInt($rental->company_id, 'rental_overdue_alert_days')));
            if (Carbon::parse($rental->expected_end_date)->gt($graceCutoff)) {
                continue;
            }

            if ($this->alreadyLoggedToday($rental->company_id, $rental->id, null, 'return_overdue')) {
                continue;
            }

            if ($this->sendReturnOverdueAlert($rental)) {
                $returnAlerts++;
            }
        }

        $invoiceQuery = RentalInvoice::query()
            ->where('status', 'unpaid')
            ->whereNotNull('due_date')
            ->with(['rental.customer:id,name,phone,whatsapp', 'rental.company:id,name']);

        if ($companyId !== null) {
            $invoiceQuery->forCompany($companyId);
        }

        foreach ($invoiceQuery->get() as $invoice) {
            if ($this->settings->get($invoice->company_id, 'whatsapp_enabled') !== '1') {
                continue;
            }

            $graceCutoff = $today->copy()->subDays(max(0, $this->settings->getInt($invoice->company_id, 'rental_overdue_alert_days')));
            if (Carbon::parse($invoice->due_date)->gt($graceCutoff)) {
                continue;
            }

            if ($this->alreadyLoggedToday($invoice->company_id, $invoice->rental_id, $invoice->id, 'payment_overdue')) {
                continue;
            }

            if ($this->sendPaymentOverdueAlert($invoice)) {
                $paymentAlerts++;
            }
        }

        return ['return_alerts' => $returnAlerts, 'payment_alerts' => $paymentAlerts];
    }

    public function buildInvoiceMessage(Company $company, Rental $rental, RentalInvoice $invoice): string
    {
        $money = fn ($n) => '₹'.number_format((float) $n, 2);
        $cycles = (float) $invoice->cycles;

        $lines = [
            "*Rental Invoice {$invoice->invoice_no}*",
            "Rental: {$rental->rental_no}",
            'Period: '.optional($invoice->period_from)->format('d M Y').' – '.optional($invoice->period_to)->format('d M Y'),
        ];

        if ($invoice->due_date) {
            $lines[] = 'Due by: '.Carbon::parse($invoice->due_date)->format('d M Y');
        }

        $lines[] = '';

        foreach ($rental->items as $item) {
            $qty = (float) $item->qty - (float) $item->returned_qty;
            if ($qty <= 0) {
                continue;
            }
            $line = $qty * (float) $item->rate_per_cycle * $cycles;
            $lines[] = "- {$item->product_name}: {$qty} x {$money($item->rate_per_cycle)} x {$cycles} cycle(s) = {$money($line)}";
        }

        $lines[] = '';
        if ((float) $rental->deposit > 0) {
            $lines[] = "Deposit held: {$money($rental->deposit)}";
        }
        $lines[] = "*Total due: {$money($invoice->amount)}*";
        $lines[] = 'Status: '.strtoupper($invoice->status);
        $lines[] = '';
        $lines[] = "Thank you for renting with {$company->name}.";

        return implode("\n", $lines);
    }

    private function sendReturnOverdueAlert(Rental $rental): bool
    {
        if ($this->settings->get($rental->company_id, 'whatsapp_enabled') !== '1') {
            return false;
        }

        $customer = $rental->customer;
        $to = $customer?->whatsapp ?: $customer?->phone;
        $companyName = $rental->company?->name ?? 'Pot & Leaf';
        $message = "*Rental overdue reminder*\n\n"
            ."Rental {$rental->rental_no} was expected back by ".Carbon::parse($rental->expected_end_date)->format('d M Y').".\n"
            ."Please return the plants or contact {$companyName} to extend the rental.";

        $result = $this->whatsapp->sendMessage($to, $message);
        $this->log($rental->company_id, $rental->id, null, 'return_overdue', $to, $result['success'] ? 'sent' : 'failed', $result['message'], $message);

        return $result['success'];
    }

    private function sendPaymentOverdueAlert(RentalInvoice $invoice): bool
    {
        if ($this->settings->get($invoice->company_id, 'whatsapp_enabled') !== '1') {
            return false;
        }

        $rental = $invoice->rental;
        $customer = $rental?->customer;
        $to = $customer?->whatsapp ?: $customer?->phone;
        $companyName = $rental?->company?->name ?? 'Pot & Leaf';
        $message = "*Payment overdue reminder*\n\n"
            ."Invoice {$invoice->invoice_no} for rental {$rental?->rental_no} is overdue.\n"
            ."Amount due: ₹".number_format((float) $invoice->amount, 2)."\n"
            ."Due date was ".Carbon::parse($invoice->due_date)->format('d M Y').".\n"
            ."Please contact {$companyName} to settle the balance.";

        $result = $this->whatsapp->sendMessage($to, $message);
        $this->log($invoice->company_id, $invoice->rental_id, $invoice->id, 'payment_overdue', $to, $result['success'] ? 'sent' : 'failed', $result['message'], $message);

        if ($result['success']) {
            $invoice->increment('reminder_count');
        }

        return $result['success'];
    }

    private function alreadyLoggedToday(int|string $companyId, ?string $rentalId, ?string $invoiceId, string $event): bool
    {
        return RentalNotificationLog::query()
            ->where('company_id', $companyId)
            ->where('event', $event)
            ->when($rentalId, fn ($q) => $q->where('rental_id', $rentalId))
            ->when($invoiceId, fn ($q) => $q->where('rental_invoice_id', $invoiceId))
            ->whereDate('created_at', Carbon::today())
            ->exists();
    }

    private function log(
        int|string $companyId,
        ?string $rentalId,
        ?string $invoiceId,
        string $event,
        ?string $recipient,
        string $status,
        ?string $note = null,
        ?string $message = null,
    ): void {
        RentalNotificationLog::create([
            'company_id'         => $companyId,
            'rental_id'          => $rentalId,
            'rental_invoice_id'  => $invoiceId,
            'channel'            => 'whatsapp',
            'event'              => $event,
            'recipient'          => $recipient,
            'status'             => $status,
            'message'            => $message ?? $note,
            'sent_at'            => $status === 'sent' ? now() : null,
        ]);
    }
}
