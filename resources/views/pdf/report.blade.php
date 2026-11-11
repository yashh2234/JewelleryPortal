<!doctype html>
<html><head><meta charset="utf-8">@include('pdf._style')
<style>@page { margin: 18px 22px; } table.grid td, table.grid th { font-size: 9px; padding: 3px 4px; } .num { text-align: right; }</style>
</head>
<body>
<table class="head"><tr>
    <td>
        <h1>{{ $tenant->name }}</h1>
        <div class="muted">{{ $tenant->address }} @if($tenant->gstin) · GSTIN {{ $tenant->gstin }} @endif</div>
    </td>
    <td class="right">
        <h2>{{ $title }}</h2>
        @if($subtitle)<div class="muted">{{ $subtitle }}</div>@endif
        <div class="muted">Printed {{ now()->format('d M Y H:i') }}</div>
    </td>
</tr></table>

<table class="grid">
    <thead><tr>@foreach($columns as $c)<th class="{{ ($c['num'] ?? false) ? 'num' : '' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse($rows as $r)
        <tr>@foreach($columns as $c)<td class="{{ ($c['num'] ?? false) ? 'num' : '' }}">{{ $r[$c['key']] ?? '' }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ count($columns) }}" class="muted">Nothing for this period.</td></tr>
    @endforelse
    </tbody>
    @if(!empty($totals))
    <tfoot><tr>@foreach($columns as $c)<th class="{{ ($c['num'] ?? false) ? 'num' : '' }}">{{ $totals[$c['key']] ?? '' }}</th>@endforeach</tr></tfoot>
    @endif
</table>
</body></html>
