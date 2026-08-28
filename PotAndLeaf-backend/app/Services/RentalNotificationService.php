<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Rental;
use App\Models\RentalInvoice;
use App\Models\RentalNotificationLog;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Carbon;

class RentalNotificationService
{
    public function __construct(
        private readonly WhatsAppService $whatsapp,
        private readonly SmsService $sms,
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

        if ($requireAutoSetting && $this->settings->get($company->id, 'rental_whatsapp_on_bill') !== '1') {
            $this->log($company->id, $rental->id, $invoice->id, 'invoice_sent', 'whatsapp', null, 'skipped', 'Auto WhatsApp on bill disabled.');

            return ['success' => false, 'message' => 'Automatic WhatsApp on billing is disabled in settings.'];
        }

        $customer = $rental->customer;
        $to = $customer?->whatsapp ?: $customer?->phone;
        $message = $this->buildInvoiceMessage($company, $rental, $invoice);
        $result = $this->dispatchCustomerMessage($company->id, $rental->id, $invoice->id, 'invoice_sent', $to, $message);

        if ($result['success']) {
            $invoice->update(['sent_at' => now()]);
        }

        return $result;
    }

    /**
     * Overdue alerts plus upcoming return/payment reminders (lead days in settings).
     *
     * @return array{return_alerts: int, payment_alerts: int, return_reminders: int, payment_reminders: int}
     */
    public function sendOverdueAlerts(?int $companyId = null): array
    {
        $returnAlerts = 0;
        $paymentAlerts = 0;
        $returnReminders = 0;
        $paymentReminders = 0;
        $today = Carbon::today();

        $rentalQuery = Rental::query()
            ->where('status', 'active')
            ->whereNotNull('expected_end_date')
            ->with(['customer:id,name,phone,whatsapp', 'company:id,name']);

        if ($companyId !== null) {
            $rentalQuery->forCompany($companyId);
        }

        foreach ($rentalQuery->get() as $rental) {
            if (! $this->channelsEnabled($rental->company_id)) {
                continue;
            }

            $end = Carbon::parse($rental->expected_end_date);
            $graceCutoff = $today->copy()->subDays(max(0, $this->settings->getInt($rental->company_id, 'rental_overdue_alert_days')));
            $leadDays = max(0, $this->settings->getInt($rental->company_id, 'rental_reminder_lead_days'));
            $reminderUntil = $today->copy()->addDays($leadDays);

            if ($end->lte($graceCutoff)) {
                if ($this->alreadyLoggedToday($rental->company_id, $rental->id, null, 'return_overdue')) {
                    continue;
                }
                if ($this->sendReturnOverdueAlert($rental)) {
                    $returnAlerts++;
                }
                continue;
            }

            if ($leadDays > 0 && $end->gt($today) && $end->lte($reminderUntil)) {
                if ($this->alreadyLoggedToday($rental->company_id, $rental->id, null, 'return_reminder')) {
                    continue;
                }
                if ($this->sendReturnReminder($rental)) {
                    $returnReminders++;
                }
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
            if (! $this->channelsEnabled($invoice->company_id)) {
                continue;
            }

            $due = Carbon::parse($invoice->due_date);
            $graceCutoff = $today->copy()->subDays(max(0, $this->settings->getInt($invoice->company_id, 'rental_overdue_alert_days')));
            $leadDays = max(0, $this->settings->getInt($invoice->company_id, 'rental_reminder_lead_days'));
            $reminderUntil = $today->copy()->addDays($leadDays);

            if ($due->lte($graceCutoff)) {
                if ($this->alreadyLoggedToday($invoice->company_id, $invoice->rental_id, $invoice->id, 'payment_overdue')) {
                    continue;
                }
                if ($this->sendPaymentOverdueAlert($invoice)) {
                    $paymentAlerts++;
                }
                continue;
            }

            if ($leadDays > 0 && $due->gt($today) && $due->lte($reminderUntil)) {
                if ($this->alreadyLoggedToday($invoice->company_id, $invoice->rental_id, $invoice->id, 'payment_reminder')) {
                    continue;
                }
                if ($this->sendPaymentReminder($invoice)) {
                    $paymentReminders++;
                }
            }
        }

        return [
            'return_alerts'     => $returnAlerts,
            'payment_alerts'    => $paymentAlerts,
            'return_reminders'  => $returnReminders,
            'payment_reminders' => $paymentReminders,
        ];
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
        $customer = $rental->customer;
        $to = $customer?->whatsapp ?: $customer?->phone;
        $companyName = $rental->company?->name ?? 'Pot & Leaf';
        $message = "*Rental overdue reminder*\n\n"
            ."Rental {$rental->rental_no} was expected back by ".Carbon::parse($rental->expected_end_date)->format('d M Y').".\n"
            ."Please return the plants or contact {$companyName} to extend the rental.";

        return $this->dispatchCustomerMessage($rental->company_id, $rental->id, null, 'return_overdue', $to, $message)['success'];
    }

    private function sendPaymentOverdueAlert(RentalInvoice $invoice): bool
    {
        $rental = $invoice->rental;
        $customer = $rental?->customer;
        $to = $customer?->whatsapp ?: $customer?->phone;
        $companyName = $rental?->company?->name ?? 'Pot & Leaf';
        $message = "*Payment overdue reminder*\n\n"
            ."Invoice {$invoice->invoice_no} for rental {$rental?->rental_no} is overdue.\n"
            ."Amount due: ₹".number_format((float) $invoice->amount, 2)."\n"
            ."Due date was ".Carbon::parse($invoice->due_date)->format('d M Y').".\n"
            ."Please contact {$companyName} to settle the balance.";

        $result = $this->dispatchCustomerMessage($invoice->company_id, $invoice->rental_id, $invoice->id, 'payment_overdue', $to, $message);

        if ($result['success']) {
            $invoice->increment('reminder_count');
        }

        return $result['success'];
    }

    private function sendReturnReminder(Rental $rental): bool
    {
        $customer = $rental->customer;
        $to = $customer?->whatsapp ?: $customer?->phone;
        $companyName = $rental->company?->name ?? 'Pot & Leaf';
        $message = "*Rental return reminder*\n\n"
            ."Rental {$rental->rental_no} is due back on ".Carbon::parse($rental->expected_end_date)->format('d M Y').".\n"
            ."Please return the plants on time or contact {$companyName} to extend.";

        return $this->dispatchCustomerMessage($rental->company_id, $rental->id, null, 'return_reminder', $to, $message)['success'];
    }

    private function sendPaymentReminder(RentalInvoice $invoice): bool
    {
        $rental = $invoice->rental;
        $customer = $rental?->customer;
        $to = $customer?->whatsapp ?: $customer?->phone;
        $companyName = $rental?->company?->name ?? 'Pot & Leaf';
        $message = "*Rental payment reminder*\n\n"
            ."Invoice {$invoice->invoice_no} for rental {$rental?->rental_no} is due on ".Carbon::parse($invoice->due_date)->format('d M Y').".\n"
            ."Amount due: ₹".number_format((float) $invoice->amount, 2)."\n"
            ."Please contact {$companyName} to settle.";

        return $this->dispatchCustomerMessage($invoice->company_id, $invoice->rental_id, $invoice->id, 'payment_reminder', $to, $message)['success'];
    }

    /** @return array{success: bool, message: string, provider?: string} */
    private function dispatchCustomerMessage(
        int|string $companyId,
        ?string $rentalId,
        ?string $invoiceId,
        string $event,
        ?string $to,
        string $message,
    ): array {
        $whatsappOn = $this->settings->get($companyId, 'whatsapp_enabled') === '1';
        $smsOn = $this->settings->get($companyId, 'sms_enabled') === '1';

        if (! $whatsappOn && ! $smsOn) {
            $this->log($companyId, $rentalId, $invoiceId, $event, 'none', $to, 'skipped', 'WhatsApp and SMS are disabled for this company.');

            return ['success' => false, 'message' => 'WhatsApp and SMS sharing are disabled for this company.'];
        }

        $success = false;
        $notes = [];
        $provider = null;

        if ($whatsappOn) {
            $result = $this->whatsapp->sendMessage($to, $message);
            $this->log($companyId, $rentalId, $invoiceId, $event, 'whatsapp', $to, $result['success'] ? 'sent' : 'failed', $result['message'], $message);
            $success = $success || $result['success'];
            $notes[] = 'WhatsApp: '.$result['message'];
            $provider = $result['provider'] ?? $provider;
        }

        if ($smsOn) {
            $result = $this->sms->sendMessage($to, $message);
            $this->log($companyId, $rentalId, $invoiceId, $event, 'sms', $to, $result['success'] ? 'sent' : 'failed', $result['message'], $message);
            $success = $success || $result['success'];
            $notes[] = 'SMS: '.$result['message'];
            $provider = $provider ? $provider.','.($result['provider'] ?? 'sms') : ($result['provider'] ?? 'sms');
        }

        return [
            'success'  => $success,
            'message'  => implode(' ', $notes) ?: 'No message sent.',
            'provider' => $provider,
        ];
    }

    private function channelsEnabled(int|string $companyId): bool
    {
        return $this->settings->get($companyId, 'whatsapp_enabled') === '1'
            || $this->settings->get($companyId, 'sms_enabled') === '1';
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
        string $channel,
        ?string $recipient,
        string $status,
        ?string $note = null,
        ?string $message = null,
    ): void {
        RentalNotificationLog::create([
            'company_id'         => $companyId,
            'rental_id'          => $rentalId,
            'rental_invoice_id'  => $invoiceId,
            'channel'            => $channel,
            'event'              => $event,
            'recipient'          => $recipient,
            'status'             => $status,
            'message'            => $message ?? $note,
            'sent_at'            => $status === 'sent' ? now() : null,
        ]);
    }
}
