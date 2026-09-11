<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Reorder Report</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 18px; }
    .co { font-size: 16px; font-weight: bold; color: #2f5233; }
    .muted { color: #666; font-size: 10px; line-height: 1.45; }
    h1 { margin: 0 0 4px; font-size: 18px; color: #2f5233; letter-spacing: 1px; }
    h2 { margin: 16px 0 6px; font-size: 13px; color: #2f5233; border-bottom: 1px solid #d7e0d2; padding-bottom: 4px; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    th, td { padding: 5px 6px; border: 1px solid #ddd; }
    th { background: #f4f7f4; font-size: 9px; text-transform: uppercase; color: #555; text-align: left; }
    .r { text-align: right; }
    .top { width: 100%; margin-bottom: 12px; border-bottom: 2px solid #2f5233; padding-bottom: 10px; }
    .warn { color: #b45309; }
  </style>
</head>
<body>
@php
  $c = $company;
  $suppliers = $report['suppliers'] ?? [];
  $unassigned = $report['unassigned'] ?? [];
@endphp

<table class="top">
  <tr>
    <td>
      <div class="co">{{ $c->legal_name ?: $c->name }}</div>
      <div class="muted">
        @if($c->address){{ $c->address }}<br>@endif
        @if($c->phone)Ph: {{ $c->phone }}@endif
        @if($c->email) · {{ $c->email }}@endif
      </div>
    </td>
    <td style="text-align:right">
      <h1>REORDER REPORT</h1>
      <div class="muted">
        Generated {{ $generated_at }}
        @if(!empty($generated_by))<br>By {{ $generated_by }}@endif
      </div>
    </td>
  </tr>
</table>

@if($layout === 'flat')
  <table>
    <thead>
      <tr>
        <th>Product</th>
        <th>SKU</th>
        <th>Supplier</th>
        <th class="r">Current Stock</th>
        <th class="r">Reorder Level</th>
        <th class="r">Required Qty</th>
        <th class="r">Suggested Qty</th>
      </tr>
    </thead>
    <tbody>
      @foreach($suppliers as $group)
        @foreach($group['items'] as $row)
          <tr>
            <td>{{ $row['name'] }}</td>
            <td>{{ $row['sku'] }}</td>
            <td>{{ $group['supplier_name'] }}</td>
            <td class="r">{{ $row['current_stock'] }}</td>
            <td class="r">{{ $row['reorder_level'] }}</td>
            <td class="r">{{ $row['required_qty'] ?? $row['shortfall'] }}</td>
            <td class="r">{{ $row['suggested_qty'] }}</td>
          </tr>
        @endforeach
      @endforeach
      @foreach($unassigned as $row)
        <tr>
          <td>{{ $row['name'] }}</td>
          <td>{{ $row['sku'] }}</td>
          <td class="warn">Supplier Not Assigned</td>
          <td class="r">{{ $row['current_stock'] }}</td>
          <td class="r">{{ $row['reorder_level'] }}</td>
          <td class="r">{{ $row['required_qty'] ?? $row['shortfall'] }}</td>
          <td class="r">{{ $row['suggested_qty'] }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
@else
  @foreach($suppliers as $group)
    <h2>Supplier: {{ $group['supplier_name'] }}</h2>
    <table>
      <thead>
        <tr>
          <th>Product</th>
          <th>SKU</th>
          <th class="r">Current</th>
          <th class="r">Reorder</th>
          <th class="r">Required</th>
          <th class="r">Suggested</th>
        </tr>
      </thead>
      <tbody>
        @foreach($group['items'] as $row)
          <tr>
            <td>{{ $row['name'] }}</td>
            <td>{{ $row['sku'] }}</td>
            <td class="r">{{ $row['current_stock'] }}</td>
            <td class="r">{{ $row['reorder_level'] }}</td>
            <td class="r">{{ $row['required_qty'] ?? $row['shortfall'] }}</td>
            <td class="r">{{ $row['suggested_qty'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endforeach

  @if(count($unassigned))
    <h2 class="warn">Supplier Not Assigned</h2>
    <table>
      <thead>
        <tr>
          <th>Product</th>
          <th>SKU</th>
          <th class="r">Current</th>
          <th class="r">Reorder</th>
          <th class="r">Required</th>
          <th class="r">Suggested</th>
        </tr>
      </thead>
      <tbody>
        @foreach($unassigned as $row)
          <tr>
            <td>{{ $row['name'] }}</td>
            <td>{{ $row['sku'] }}</td>
            <td class="r">{{ $row['current_stock'] }}</td>
            <td class="r">{{ $row['reorder_level'] }}</td>
            <td class="r">{{ $row['required_qty'] ?? $row['shortfall'] }}</td>
            <td class="r">{{ $row['suggested_qty'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
@endif
</body>
</html>
