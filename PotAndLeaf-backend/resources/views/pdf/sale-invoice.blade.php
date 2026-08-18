<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Invoice {{ $sale->sale_no }}</title>
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
    .totals { width: 240px; margin-left: auto; margin-top: 12px; }
    .totals td { border: 0; padding: 3px 0; }
    .grand { font-size: 13px; font-weight: bold; border-top: 1px solid #ccc !important; padding-top: 6px !important; }
    .hsn { font-size: 9px; color: #888; }
  </style>
</head>
<body>
@php
  $c = $sale->company;
  $inter = (bool) $sale->is_interstate;
  $money = fn ($n) => '₹' . number_format((float) $n, 2);
@endphp

<table class="top">
  <tr>
    <td>
      <div class="co">{{ $c->legal_name ?: $c->name }}</div>
      <div class="muted">
        @if($c->address){{ $c->address }}<br>@endif
        @if($c->phone)Ph: {{ $c->phone }}@endif
        @if($c->email) · {{ $c->email }}@endif
        @if($c->gst_number)<br>GSTIN: {{ $c->gst_number }}@endif
        @if($c->state)<br>{{ $c->state }}@if($c->state_code) ({{ $c->state_code }})@endif @endif
      </div>
    </td>
    <td style="text-align:right">
      <h1>TAX INVOICE</h1>
      <div class="muted">No. {{ $sale->sale_no }}<br>Date {{ optional($sale->sale_date)->format('d M Y') }}</div>
    </td>
  </tr>
</table>

<table class="parties">
  <tr>
    <td>
      <div class="lbl">Bill to</div>
      <strong>{{ $sale->customer_name }}</strong>
      <div class="muted">
        @if($sale->customer?->address_line1){{ $sale->customer->address_line1 }}<br>@endif
        @if($sale->customer?->city || $sale->customer?->state)
          {{ collect([$sale->customer?->city, $sale->customer?->state])->filter()->join(', ') }}<br>
        @endif
        @if($sale->customer?->gst_number)GSTIN: {{ $sale->customer->gst_number }}@endif
        @if($sale->customer?->phone)<br>Ph: {{ $sale->customer->phone }}@endif
      </div>
    </td>
    <td>
      <div class="lbl">Payment</div>
      <div>{{ strtoupper($sale->payment_mode) }}</div>
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
      <th class="r">Disc.</th>
      <th class="r">Taxable</th>
      <th class="r">Tax</th>
      <th class="r">Total</th>
    </tr>
  </thead>
  <tbody>
    @foreach($sale->items as $i => $it)
      @php $gst = (float)$it->cgst_amount + (float)$it->sgst_amount + (float)$it->igst_amount; @endphp
      <tr>
        <td class="c">{{ $i + 1 }}</td>
        <td>{{ $it->product_name }}@if($it->hsn_code)<div class="hsn">HSN {{ $it->hsn_code }}</div>@endif</td>
        <td class="r">{{ $it->qty }}</td>
        <td class="r">{{ $money($it->rate) }}</td>
        <td class="r">{{ $money($it->discount) }}</td>
        <td class="r">{{ $money($it->taxable_value) }}</td>
        <td class="r">{{ $money($gst) }}<div class="hsn">{{ $it->gst_rate }}%</div></td>
        <td class="r">{{ $money($it->line_total) }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

<table class="totals">
  <tr><td>Subtotal</td><td class="r">{{ $money($sale->subtotal) }}</td></tr>
  @if($inter)
    <tr><td>IGST</td><td class="r">{{ $money($sale->tax_total) }}</td></tr>
  @else
    <tr><td>CGST</td><td class="r">{{ $money($sale->tax_total / 2) }}</td></tr>
    <tr><td>SGST</td><td class="r">{{ $money($sale->tax_total / 2) }}</td></tr>
  @endif
  @if((float)$sale->round_off != 0)
    <tr><td>Round off</td><td class="r">{{ $money($sale->round_off) }}</td></tr>
  @endif
  @if((float)$sale->loyalty_discount > 0)
    <tr><td>Loyalty discount</td><td class="r">−{{ $money($sale->loyalty_discount) }}</td></tr>
  @endif
  <tr class="grand"><td>Grand total</td><td class="r">{{ $money($sale->grand_total) }}</td></tr>
  <tr><td>Amount paid</td><td class="r">{{ $money($sale->amount_paid) }}</td></tr>
</table>

@if($sale->notes)
  <p class="muted" style="margin-top:16px">Notes: {{ $sale->notes }}</p>
@endif
<p class="muted" style="margin-top:24px">This is a computer-generated invoice.</p>
</body>
</html>
