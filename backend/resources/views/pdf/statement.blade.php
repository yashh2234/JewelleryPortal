<!doctype html>
<html><head><meta charset="utf-8">@include('pdf._style')
<style>table.grid td, table.grid th { font-size: 9px; padding: 3px 4px; } .num { text-align: right; } .cancelled { color: #999; text-decoration: line-through; }</style>
</head>
<body>
@php
    $inr = fn ($v) => number_format((float) $v, 2);
    $g = fn ($v) => number_format((float) $v, 3);
    $side = fn ($v) => (float) $v >= 0 ? 'Dr' : 'Cr';
@endphp
<table class="head"><tr>
    <td>
        <h1>{{ $tenant->name }}</h1>
        <div class="muted">{{ $tenant->address }} @if($tenant->gstin) · GSTIN {{ $tenant->gstin }} @endif</div>
    </td>
    <td class="right">
        <h2>Account statement</h2>
        <div class="muted">{{ \Carbon\Carbon::parse($l['from'])->format('d M Y') }} – {{ \Carbon\Carbon::parse($l['to'])->format('d M Y') }}</div>
    </td>
</tr></table>

<table style="margin-bottom:10px"><tr>
    <td class="box" style="width:60%">
        <b>{{ $retailer->shop_name }}</b><br>
        {{ $retailer->address }} {{ $retailer->city }}<br>
        @if($retailer->gstin) GSTIN {{ $retailer->gstin }} @endif
    </td>
    <td class="box">
        <span class="muted">Opening</span> <b>₹{{ $inr(abs($l['opening']['money'])) }} {{ $side($l['opening']['money']) }}</b><br>
        <span class="muted">Closing</span> <b>₹{{ $inr(abs($l['closing']['money'])) }} {{ $side($l['closing']['money']) }}</b>
        @if($metal)<br><span class="muted">Metal closing</span> <b>{{ $g(abs($l['closing']['fine'])) }} g {{ $side($l['closing']['fine']) }}</b>@endif
    </td>
</tr></table>

<table class="grid">
    <thead><tr>
        <th>Date</th><th>Voucher</th><th>Particulars</th>
        <th class="num">Debit ₹</th><th class="num">Credit ₹</th><th class="num">Balance ₹</th>
        @if($metal)<th class="num">Metal Dr g</th><th class="num">Metal Cr g</th>@endif
    </tr></thead>
    <tbody>
    <tr><td></td><td></td><td><i>Opening balance</i></td><td></td><td></td><td class="num">{{ $inr(abs($l['opening']['money'])) }} {{ $side($l['opening']['money']) }}</td>@if($metal)<td></td><td></td>@endif</tr>
    @foreach($l['rows'] as $r)
        <tr class="{{ $r['cancelled'] ? 'cancelled' : '' }}">
            <td>{{ \Carbon\Carbon::parse($r['date'])->format('d M Y') }}</td>
            <td>{{ $r['voucher_no'] }}</td>
            <td>{{ ucfirst(str_replace('_', ' ', $r['type'])) }}@if($r['narration']) · {{ $r['narration'] }}@endif</td>
            <td class="num">{{ (float) $r['debit'] ? $inr($r['debit']) : '' }}</td>
            <td class="num">{{ (float) $r['credit'] ? $inr($r['credit']) : '' }}</td>
            <td class="num">{{ $inr(abs($r['balance'])) }} {{ $side($r['balance']) }}</td>
            @if($metal)<td class="num">{{ (float) $r['fine_dr'] ? $g($r['fine_dr']) : '' }}</td><td class="num">{{ (float) $r['fine_cr'] ? $g($r['fine_cr']) : '' }}</td>@endif
        </tr>
    @endforeach
    </tbody>
</table>
<p class="muted" style="margin-top:8px">Dr = amount due from you · Cr = amount in your favour. Money and metal accounts are kept separately.</p>
</body></html>
