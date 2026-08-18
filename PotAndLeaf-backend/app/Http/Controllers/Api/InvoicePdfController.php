<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\RentalInvoice;
use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvoicePdfController extends Controller
{
    public function sale(Request $request, Sale $sale): Response
    {
        $company = $request->attributes->get('company');
        abort_unless($request->user()->hasPermission('sales.view', $company->id), 403);
        abort_unless((string) $sale->company_id === (string) $company->id, 404);

        $sale->load([
            'items',
            'customer:id,name,type,gst_number,phone,address_line1,city,state',
            'company:id,name,legal_name,gst_number,address,phone,email,state,state_code',
        ]);

        $pdf = Pdf::loadView('pdf.sale-invoice', ['sale' => $sale])
            ->setPaper('a4');

        return $pdf->download("invoice-{$sale->sale_no}.pdf");
    }

    public function purchase(Request $request, Purchase $purchase): Response
    {
        $company = $request->attributes->get('company');
        abort_unless($request->user()->hasPermission('purchases.view', $company->id), 403);
        abort_unless((string) $purchase->company_id === (string) $company->id, 404);

        $purchase->load([
            'items',
            'supplier:id,name,supplier_code,gst_number,phone,address_line1,city,state',
            'company:id,name,legal_name,gst_number,address,phone,email,state,state_code',
        ]);

        $pdf = Pdf::loadView('pdf.purchase-grn', ['purchase' => $purchase])
            ->setPaper('a4');

        return $pdf->download("grn-{$purchase->purchase_no}.pdf");
    }

    public function rental(Request $request, RentalInvoice $rentalInvoice): Response
    {
        $company = $request->attributes->get('company');
        abort_unless($request->user()->hasPermission('rental.view', $company->id), 403);
        abort_unless((string) $rentalInvoice->company_id === (string) $company->id, 404);

        $rentalInvoice->load([
            'rental.items',
            'rental.customer:id,name,phone,whatsapp,address_line1,city,state',
            'rental.company:id,name,legal_name,gst_number,address,phone,email,state,state_code',
        ]);

        $pdf = Pdf::loadView('pdf.rental-invoice', ['invoice' => $rentalInvoice, 'rental' => $rentalInvoice->rental])
            ->setPaper('a4');

        return $pdf->download("rental-invoice-{$rentalInvoice->invoice_no}.pdf");
    }
}
