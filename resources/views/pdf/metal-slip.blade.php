@php
    $titles = [
        'issue' => 'Gold issue slip', 'receive' => 'Gold receive slip', 'purchase' => 'Purchase voucher',
        'opening' => 'Opening stock', 'deposit' => 'Metal deposit receipt', 'melt' => 'Melting voucher',
        'refine' => 'Refining voucher', 'transfer' => 'Transfer voucher', 'loss_writeoff' => 'Wastage write-off',
        'reversal' => 'Reversal voucher',
    ];
    $kindLabel = ['metal' => 'Gold', 'work' => 'Work', 'scrap' => 'Scrap', 'loss' => 'Loss', 'alloy' => 'Alloy', 'deposit' => 'Deposit'];
    $fineTotal = \App\Support\Metal\Weight::add('0', ...$tx->lines->whereNotIn('kind', ['loss', 'alloy'])->pluck('fine_wt')->all());
@endphp
<!doctype html>
<html><head><meta charset="utf-8">@include('pdf._style')</head>
<body>
<table class="head"><tr>
    <td>
        <h1>{{ $tenant->name }}</h1>
        <div class="muted">{{ $tenant->address }} @if($tenant->gstin) · GSTIN {{ $tenant->gstin }} @endif</div>
    </td>
    <td class="right">
        <h2>{{ $titles[$tx->type] ?? ucfirst($tx->type) }}</h2>
        <div><b>{{ $tx->voucher_no }}</b></div>
        <div class="muted">{{ \Carbon\Carbon::parse($tx->date)->format('d M Y') }}</div>
    </td>
</tr></table>

<table style="margin-bottom:10px"><tr>
    @if($tx->kaarigar)
        <td><span class="muted">Kaarigar</span><br><b>{{ $tx->kaarigar->name }}</b> {{ $tx->kaarigar->phone }}</td>
    @endif
    @if($tx->jobCard)
        <td><span class="muted">Job card</span><br><b>{{ $tx->jobCard->card_no }}</b> · {{ $tx->jobCard->process?->name }}</td>
    @endif
    @if($tx->retailer)
        <td><span class="muted">Retailer</span><br><b>{{ $tx->retailer->shop_name }}</b></td>
    @endif
    @if($tx->party_name)
        <td><span class="muted">Party</span><br><b>{{ $tx->party_name }}</b></td>
    @endif
    @if($tx->reference)
        <td><span class="muted">Reference</span><br>{{ $tx->reference }}</td>
    @endif
</tr></table>

<table>
    <thead><tr>
        <th>Item</th><th>From → To</th><th>Karat</th>
        <th class="right">Gross g</th><th class="right">Stone g</th><th class="right">Net g</th>
        <th class="right">Purity %</th><th class="right">Fine g</th><th class="right">Pcs</th>
    </tr></thead>
    <tbody>
    @foreach($tx->lines as $l)
        <tr>
            <td>{{ $kindLabel[$l->kind] ?? $l->kind }}</td>
            <td>{{ $l->from->name }} → {{ $l->to->name }}</td>
            <td>{{ $l->karat->label }}</td>
            <td class="num">{{ $l->gross_wt }}</td>
            <td class="num">{{ $l->stone_wt }}</td>
            <td class="num">{{ $l->net_wt }}</td>
            <td class="num">{{ $l->purity_pct }}</td>
            <td class="num">{{ $l->fine_wt }}</td>
            <td class="num">{{ $l->pieces }}</td>
        </tr>
    @endforeach
    <tr class="total"><td colspan="7">Total fine</td><td class="num">{{ $fineTotal }}</td><td></td></tr>
    </tbody>
</table>

@if($tx->remarks)<p><span class="muted">Remarks:</span> {{ $tx->remarks }}</p>@endif
@if($tx->over_limit)<p><span class="badge">Loss above the allowed wastage</span></p>@endif

<table class="sign"><tr>
    <td><span>{{ $tx->kaarigar ? 'Kaarigar' : 'Received by' }}</span></td>
    <td class="right"><span>For {{ $tenant->name }}</span><br><small class="muted">Entered by {{ $tx->creator?->name }}</small></td>
</tr></table>
</body></html>
