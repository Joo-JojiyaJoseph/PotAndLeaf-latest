<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>GRN {{ $purchase->purchase_no }}</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 20px; }
    .co { font-size: 16px; font-weight: bold; color: #2f5233; }
    .muted { color: #666; font-size: 10px; line-height: 1.45; }
    h1 { margin: 0; font-size: 18px; color: #2f5233; letter-spacing: 1px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 6px 7px; border-bottom: 1px solid #eee; }
    th { background: #f4f7f4; font-size: 9px; text-transform: uppercase; color: #555; text-align: left; }
    .r { text-align: right; }
    .c { text-align: center; }
    .top { width: 100%; margin-bottom: 12px; border-bottom: 2px solid #2f5233; padding-bottom: 10px; }
    .parties td { border: 0; vertical-align: top; width: 50%; padding: 8px 0; }
    .lbl { text-transform: uppercase; font-size: 8px; letter-spacing: .5px; color: #999; }
    .totals { width: 260px; margin-left: auto; margin-top: 12px; }
    .totals td { border: 0; padding: 3px 0; }
    .grand { font-size: 13px; font-weight: bold; border-top: 1px solid #ccc !important; padding-top: 6px !important; }
  </style>
</head>
<body>
@php
  $c = $purchase->company;
  $inter = (bool) $purchase->is_interstate;
  $money = fn ($n) => '₹' . number_format((float) $n, 2);
@endphp

<table class="top">
  <tr>
    <td>
      <div class="co">{{ $c->legal_name ?: $c->name }}</div>
      <div class="muted">
        @if($c->gst_number)GSTIN: {{ $c->gst_number }}<br>@endif
        @if($c->address){{ $c->address }}@endif
      </div>
    </td>
    <td style="text-align:right">
      <h1>GOODS RECEIPT</h1>
      <div class="muted">No. {{ $purchase->purchase_no }}<br>Date {{ optional($purchase->purchase_date)->format('d M Y') }}</div>
      @if($purchase->invoice_no)
        <div class="muted">Supplier inv. {{ $purchase->invoice_no }}</div>
      @endif
    </td>
  </tr>
</table>

<table class="parties">
  <tr>
    <td>
      <div class="lbl">Supplier</div>
      <strong>{{ $purchase->supplier?->name }}</strong>
      <div class="muted">
        @if($purchase->supplier?->gst_number)GSTIN: {{ $purchase->supplier->gst_number }}@endif
      </div>
    </td>
    <td>
      <div class="lbl">Supply type</div>
      <div class="muted">{{ $inter ? 'Inter-state (IGST)' : 'Intra-state (CGST + SGST)' }}</div>
    </td>
  </tr>
</table>

<table>
  <thead>
    <tr>
      <th class="c" style="width:28px">#</th>
      <th>Item</th>
      <th class="r">Qty</th>
      <th class="r">Rate</th>
      <th class="r">Taxable</th>
      <th class="r">Tax</th>
      <th class="r">Total</th>
    </tr>
  </thead>
  <tbody>
    @foreach($purchase->items as $i => $it)
      @php $gst = (float)($it->cgst_amount ?? 0) + (float)($it->sgst_amount ?? 0) + (float)($it->igst_amount ?? 0); @endphp
      <tr>
        <td class="c">{{ $i + 1 }}</td>
        <td>{{ $it->product_name }}</td>
        <td class="r">{{ $it->qty }}</td>
        <td class="r">{{ $money($it->rate) }}</td>
        <td class="r">{{ $money($it->taxable_value) }}</td>
        <td class="r">{{ $money($gst) }}</td>
        <td class="r">{{ $money($it->line_total) }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

<table class="totals">
  <tr><td>Subtotal</td><td class="r">{{ $money($purchase->subtotal) }}</td></tr>
  <tr><td>{{ $inter ? 'IGST' : 'CGST + SGST' }}</td><td class="r">{{ $money($purchase->tax_total) }}</td></tr>
  @if((float)$purchase->landed_cost_total > 0)
    <tr><td>Landed costs</td><td class="r">{{ $money($purchase->landed_cost_total) }}</td></tr>
  @endif
  <tr class="grand"><td>Grand total</td><td class="r">{{ $money($purchase->grand_total) }}</td></tr>
</table>
</body>
</html>
