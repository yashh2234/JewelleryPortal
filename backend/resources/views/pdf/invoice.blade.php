@php
    $t = $v->meta['totals'] ?? [];
    $metalTerms = ($v->meta['terms'] ?? 'cash') === 'metal';
    $irn = $v->meta['einvoice']['irn'] ?? null;
    $inr = fn ($n) => number_format((float) $n, 2);
@endphp
<!doctype html>
<html><head><meta charset="utf-8">@include('pdf._style')</head>
<body>
<table class="head"><tr>
    <td>
        <h1>{{ $tenant->name }}</h1>
        <div class="muted">{{ $tenant->address }}</div>
        @if($tenant->gstin)<div>GSTIN <b>{{ $tenant->gstin }}</b> · State {{ $tenant->state_code }}</div>@endif
    </td>
    <td class="right">
        <h2>Tax invoice</h2>
        <div><b>{{ $v->voucher_no }}</b></div>
        <div class="muted">{{ \Carbon\Carbon::parse($v->date)->format('d M Y') }}</div>
        @if(! empty($v->meta['challan_no']))<div class="muted">Challan {{ $v->meta['challan_no'] }}</div>@endif
        @if($v->status === 'cancelled')<div class="badge">CANCELLED</div>@endif
    </td>
</tr></table>

<table style="margin-bottom:10px"><tr>
    <td class="box" style="width:60%">
        <span class="muted">Bill to</span><br>
        <b>{{ $v->party->name }}</b><br>{{ $v->party->address }}<br>
        @if($v->party->gstin)GSTIN {{ $v->party->gstin }} · @endif State {{ $v->party->state_code ?? $tenant->state_code }}
    </td>
    <td class="box">
        <span class="muted">Terms</span> <b>{{ $metalTerms ? 'Metal account' : 'Cash' }}</b><br>
        <span class="muted">Supply</span> <b>{{ ($v->meta['inter_state'] ?? false) ? 'Inter-state (IGST)' : 'Intra-state (CGST + SGST)' }}</b>
        @if($irn)<br><span class="muted">IRN</span> <span style="font-size:7px">{{ $irn }}</span>@endif
    </td>
</tr></table>

<table>
    <thead><tr>
        <th>#</th><th>Description</th><th>HSN</th><th>HUID</th>
        <th class="right">Net g</th><th class="right">Rate ₹/g</th><th class="right">Metal ₹</th><th class="right">Making ₹</th>
        <th class="right">Other ₹</th><th class="right">Taxable ₹</th><th class="right">GST</th><th class="right">Total ₹</th>
    </tr></thead>
    <tbody>
    @foreach($v->invoiceLines as $i => $l)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $l->description }}<br><span class="muted">gross {{ $l->gross_wt }} · {{ $l->purity_pct }}% · fine {{ $l->fine_wt }}</span></td>
            <td>{{ $l->hsn }}</td>
            <td><b>{{ $l->huid }}</b></td>
            <td class="num">{{ $l->net_wt }}</td>
            <td class="num">{{ $inr($l->rate) }}</td>
            <td class="num">{{ $inr($l->metal_value) }}</td>
            <td class="num">{{ $inr($l->making) }}</td>
            <td class="num">{{ $inr($l->stone_charge + $l->hallmark_charge) }}</td>
            <td class="num">{{ $inr($l->taxable) }}</td>
            <td class="num">{{ $l->gst_rate }}%</td>
            <td class="num">{{ $inr($l->total) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table style="margin-top:10px"><tr>
    <td style="width:55%" class="muted">
        @if($metalTerms)
            Metal of <b>{{ $t['fine_wt'] ?? '' }} g fine</b> is settled from your metal account.
            The amount payable excludes the metal value of ₹{{ $inr($t['metal_value'] ?? 0) }}.
        @endif
    </td>
    <td>
        <table>
            <tr><td>Taxable value</td><td class="num">{{ $inr($t['taxable'] ?? 0) }}</td></tr>
            @if((float)($t['igst'] ?? 0) > 0)
                <tr><td>IGST</td><td class="num">{{ $inr($t['igst']) }}</td></tr>
            @else
                <tr><td>CGST</td><td class="num">{{ $inr($t['cgst'] ?? 0) }}</td></tr>
                <tr><td>SGST</td><td class="num">{{ $inr($t['sgst'] ?? 0) }}</td></tr>
            @endif
            <tr class="total"><td>Invoice total</td><td class="num">{{ $inr($t['total'] ?? $v->amount) }}</td></tr>
            @if($metalTerms)<tr class="total"><td>Payable in money</td><td class="num">{{ $inr($t['payable'] ?? 0) }}</td></tr>@endif
        </table>
    </td>
</tr></table>

<table class="sign"><tr>
    <td><span>Receiver's signature</span></td>
    <td class="right"><span>For {{ $tenant->name }}</span></td>
</tr></table>
</body></html>
