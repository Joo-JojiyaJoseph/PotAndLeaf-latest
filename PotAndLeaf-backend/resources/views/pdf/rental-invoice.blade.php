<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Rental Invoice {{ $invoice->invoice_no }}</title>
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
  </style>
</head>
<body>
@php
  $c = $rental->company;
  $customer = $rental->customer;
  $money = fn ($n) => '₹' . number_format((float) $n, 2);
  $cycles = (float) $invoice->cycles;
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
      <h1>RENTAL INVOICE</h1>
      <div class="muted">No. {{ $invoice->invoice_no }}<br>Rental {{ $rental->rental_no }}</div>
    </td>
  </tr>
</table>

<table class="parties">
  <tr>
    <td>
      <div class="lbl">Bill to</div>
      <strong>{{ $customer?->name }}</strong>
      <div class="muted">
        @if($customer?->address_line1){{ $customer->address_line1 }}<br>@endif
        @if($customer?->city || $customer?->state)
          {{ collect([$customer?->city, $customer?->state])->filter()->join(', ') }}<br>
        @endif
        @if($customer?->phone)Ph: {{ $customer->phone }}@endif
      </div>
    </td>
    <td>
      <div class="lbl">Billing period</div>
      <div>{{ optional($invoice->period_from)->format('d M Y') }} – {{ optional($invoice->period_to)->format('d M Y') }}</div>
      <div class="muted">{{ $cycles }} × {{ $rental->billing_cycle }} cycle(s)</div>
      <div class="muted">Status: {{ strtoupper($invoice->status) }}</div>
    </td>
  </tr>
</table>

<table>
  <thead>
    <tr>
      <th class="c" style="width:28px">#</th>
      <th>Plant</th>
      <th class="r">Qty</th>
      <th class="r">Rate / cycle</th>
      <th class="r">Cycles</th>
      <th class="r">Amount</th>
    </tr>
  </thead>
  <tbody>
    @foreach($rental->items as $i => $it)
      @php
        $qty = (float) $it->qty - (float) $it->returned_qty;
        $line = $qty * (float) $it->rate_per_cycle * $cycles;
      @endphp
      <tr>
        <td class="c">{{ $i + 1 }}</td>
        <td>{{ $it->product_name }}</td>
        <td class="r">{{ $qty }}</td>
        <td class="r">{{ $money($it->rate_per_cycle) }}</td>
        <td class="r">{{ $cycles }}</td>
        <td class="r">{{ $money($line) }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

<table class="totals">
  @if((float) $rental->deposit > 0)
    <tr><td>Deposit held</td><td class="r">{{ $money($rental->deposit) }}</td></tr>
  @endif
  <tr class="grand"><td>Total due</td><td class="r">{{ $money($invoice->amount) }}</td></tr>
</table>

@if($invoice->notes)
  <p class="muted" style="margin-top:16px">Notes: {{ $invoice->notes }}</p>
@endif
<p class="muted" style="margin-top:24px">This is a computer-generated rental invoice.</p>
</body>
</html>
