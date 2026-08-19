<?php

namespace App\Jobs;

use App\Models\RentalInvoice;
use App\Services\RentalNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendRentalInvoiceWhatsApp implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $invoiceId) {}

    public function handle(RentalNotificationService $notifications): void
    {
        $invoice = RentalInvoice::with(['rental.items', 'rental.customer:id,name,phone,whatsapp', 'rental.company:id,name,legal_name,gst_number,address,phone,state,state_code'])
            ->find($this->invoiceId);

        if ($invoice) {
            $notifications->sendInvoiceWhatsApp($invoice);
        }
    }
}
