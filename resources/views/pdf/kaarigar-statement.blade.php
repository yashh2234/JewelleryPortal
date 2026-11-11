@php
    $url = request()->getSchemeAndHttpHost().'/khata/'.$kaarigar->slip_token;
@endphp
<!doctype html>
<html><head><meta charset="utf-8">@include('pdf._style')</head>
<body>
<table class="head"><tr>
    <td>
        <h1>{{ $tenant->name }}</h1>
        <div class="muted">Gold khata (kaarigar statement)</div>
        <h2 style="margin-top:6px">{{ $kaarigar->name }} @if($kaarigar->phone)<span class="muted">· {{ $kaarigar->phone }}</span>@endif</h2>
        <div class="muted">{{ \Carbon\Carbon::parse($from)->format('d M Y') }} – {{ $to ? \Carbon\Carbon::parse($to)->format('d M Y') : 'today' }}</div>
    </td>
    <td class="right" style="width:90px">
        <img src="{{ \App\Support\Qr::dataUri($url, 3) }}" width="80"><br>
        <small class="muted">Scan for latest</small>
    </td>
</tr></table>

<table>
    <thead><tr>
        <th>Date</th><th>Voucher</th><th>Entry</th><th>Job card</th><th>Karat</th>
        <th class="right">Gross g</th><th class="right">In (fine)</th><th class="right">Out (fine)</th><th class="right">Balance</th>
    </tr></thead>
    <tbody>
    <tr><td colspan="8"><b>Opening balance</b></td><td class="num"><b>{{ $statement['opening'] }}</b></td></tr>
    @foreach($statement['rows'] as $r)
        <tr>
            <td>{{ \Carbon\Carbon::parse($r['date'])->format('d M') }}</td>
            <td>{{ $r['voucher_no'] }}</td>
            <td>{{ ucfirst($r['kind']) }}@if($r['process']) · {{ $r['process'] }}@endif</td>
            <td>{{ $r['job_card'] }}</td>
            <td>{{ $r['karat'] }}</td>
            <td class="num">{{ $r['gross_wt'] }}</td>
            <td class="num">{{ $r['in'] }}</td>
            <td class="num">{{ $r['out'] }}</td>
            <td class="num">{{ $r['balance'] }}</td>
        </tr>
    @endforeach
    <tr class="total"><td colspan="8">Gold in hand (fine)</td><td class="num">{{ $statement['closing'] }}</td></tr>
    </tbody>
</table>

<table style="margin-top:12px"><tr>
    <td class="box">Issued <b>{{ $statement['totals']['issued'] }}</b> g</td>
    <td class="box">Returned <b>{{ $statement['totals']['returned'] }}</b> g</td>
    <td class="box">Scrap <b>{{ $statement['totals']['scrap'] }}</b> g</td>
    <td class="box">Wastage <b>{{ $statement['totals']['loss'] }}</b> g</td>
</tr></table>

<table class="sign"><tr>
    <td><span>Kaarigar</span></td>
    <td class="right"><span>For {{ $tenant->name }}</span></td>
</tr></table>
</body></html>
