<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>{{ $title }}</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
    h1 { font-size: 16px; margin: 0 0 4px; }
    .meta { color: #666; margin-bottom: 12px; font-size: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
    th { background: #f3f4f6; font-size: 10px; text-transform: uppercase; }
    td.num { text-align: right; font-variant-numeric: tabular-nums; }
  </style>
</head>
<body>
  <h1>{{ $title }}</h1>
  <div class="meta">
    @foreach($meta as $k => $v)
      <span>{{ $k }}: {{ $v }}</span> &nbsp;
    @endforeach
    Generated: {{ $generated_at }}
  </div>
  <table>
    <thead>
      <tr>
        @foreach($headers as $h)
          <th>{{ $labels[$h] ?? $h }}</th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @forelse($rows as $row)
        <tr>
          @foreach($headers as $h)
            @php $val = $row[$h] ?? ''; @endphp
            <td class="{{ is_numeric($val) ? 'num' : '' }}">{{ $val }}</td>
          @endforeach
        </tr>
      @empty
        <tr><td colspan="{{ count($headers) }}">No rows</td></tr>
      @endforelse
    </tbody>
  </table>
</body>
</html>
