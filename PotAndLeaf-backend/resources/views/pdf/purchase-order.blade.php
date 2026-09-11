<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Purchase Order {{ $po->po_no }}</title>
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
    .notes { margin-top: 18px; font-size: 10px; color: #555; }
  </style>
</head>
<body>
@php
  $c = $po->company;
  $s = $po->supplier;
  $money = fn ($n) => '₹' . number_format((float) $n, 2);
  $addr = collect([
      $s?->address_line1, $s?->address_line2, $s?->city, $s?->state, $s?->pincode,
  ])->filter()->implode(', ');
  if ($addr === '' && $s?->address) {
      $addr = $s->address;
  }
@endphp

<table class="top">
  <tr>
    <td>
      <div class="co">{{ $c->legal_name ?: $c->name }}</div>
      <div class="muted">
        @if($c->gst_number)GSTIN: {{ $c->gst_number }}<br>@endif
        @if($c->address){{ $c->address }}<br>@endif
        @if($c->phone)Ph: {{ $c->phone }}@endif
        @if($c->email) · {{ $c->email }}@endif
      </div>
    </td>
    <td style="text-align:right">
      <h1>PURCHASE ORDER</h1>
      <div class="muted">No. {{ $po->po_no }}<br>Date {{ optional($po->po_date)->format('d M Y') }}</div>
      @if($po->expected_date)
        <div class="muted">Expected {{ $po->expected_date->format('d M Y') }}</div>
      @endif
      <div class="muted">Status: {{ strtoupper($po->status) }}</div>
    </td>
  </tr>
</table>

<table class="parties">
  <tr>
    <td>
      <div class="lbl">Supplier</div>
      <strong>{{ $s?->name }}</strong>
      <div class="muted">
        @if($s?->supplier_code){{ $s->supplier_code }}<br>@endif
        @if($addr){{ $addr }}<br>@endif
        @if($s?->phone)Ph: {{ $s->phone }}@endif
        @if($s?->email) · {{ $s->email }}@endif
        @if($s?->gst_number)<br>GSTIN: {{ $s->gst_number }}@endif
      </div>
    </td>
    <td>
      <div class="lbl">Prepared by</div>
      <div class="muted">{{ $po->createdBy?->name ?: '—' }}</div>
    </td>
  </tr>
</table>

<table>
  <thead>
    <tr>
      <th class="c" style="width:28px">#</th>
      <th>Product</th>
      <th>SKU</th>
      <th class="r">Qty</th>
      <th class="r">Rate</th>
      <th class="r">GST %</th>
      <th class="r">Taxable</th>
      <th class="r">Tax</th>
      <th class="r">Total</th>
    </tr>
  </thead>
  <tbody>
    @foreach($po->items as $i => $it)
      @php $tax = (float) $it->line_total - (float) $it->taxable_value; @endphp
      <tr>
        <td class="c">{{ $i + 1 }}</td>
        <td>{{ $it->product_name }}</td>
        <td>{{ $it->product?->sku }}</td>
        <td class="r">{{ $it->qty }}</td>
        <td class="r">{{ $money($it->rate) }}</td>
        <td class="r">{{ number_format((float) $it->gst_rate, 2) }}</td>
        <td class="r">{{ $money($it->taxable_value) }}</td>
        <td class="r">{{ $money($tax) }}</td>
        <td class="r">{{ $money($it->line_total) }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

<table class="totals">
  <tr><td>Subtotal</td><td class="r">{{ $money($po->subtotal) }}</td></tr>
  <tr><td>Tax / GST</td><td class="r">{{ $money($po->tax_total) }}</td></tr>
  <tr class="grand"><td>Grand total</td><td class="r">{{ $money($po->grand_total) }}</td></tr>
</table>

@if($po->notes)
  <div class="notes"><strong>Notes</strong><br>{{ $po->notes }}</div>
@endif
<div class="notes">
  <strong>Terms &amp; conditions</strong><br>
  This purchase order does not increase stock. Goods are received only after GRN confirmation.
</div>
</body>
</html>
