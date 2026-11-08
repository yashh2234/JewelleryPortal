@php
    $byCategory = $lot->items->groupBy(fn ($li) => $li->finishedItem->design->category?->name ?? 'Other');
@endphp
<!doctype html>
<html><head><meta charset="utf-8">@include('pdf._style')</head>
<body>
<table class="head"><tr>
    <td>
        <h1>{{ $tenant->name }}</h1>
        <div class="muted">Hallmarking lot sheet @if($tenant->gstin) · GSTIN {{ $tenant->gstin }} @endif</div>
    </td>
    <td class="right">
        <h2>{{ $lot->lot_no }}</h2>
        <div>AHC: <b>{{ $lot->ahc->name }}</b> @if($lot->ahc->bis_recognition_no)({{ $lot->ahc->bis_recognition_no }})@endif</div>
        <div class="muted">Declared purity {{ $lot->karat->label }} ({{ $lot->karat->purity_pct }}%)</div>
        @if($lot->manak_request_no)<div>Manak request {{ $lot->manak_request_no }}</div>@endif
    </td>
</tr></table>

<h2>For Manak Online: totals per category</h2>
<table style="margin-bottom:12px">
    <thead><tr><th>Item category</th><th class="right">Quantity</th><th class="right">Weight (g)</th><th class="right">Declared purity</th></tr></thead>
    <tbody>
    @foreach($byCategory as $category => $items)
        <tr>
            <td>{{ $category }}</td>
            <td class="num">{{ $items->count() }}</td>
            <td class="num">{{ \App\Support\Metal\Weight::add('0', ...$items->pluck('sent_wt')->all()) }}</td>
            <td class="num">{{ $lot->karat->label }}</td>
        </tr>
    @endforeach
    <tr class="total"><td>Total</td><td class="num">{{ $lot->pieces }}</td><td class="num">{{ $lot->gross_wt }}</td><td></td></tr>
    </tbody>
</table>

<h2>Pieces</h2>
<table>
    <thead><tr><th>#</th><th>Tag</th><th>Design</th><th>Size</th><th class="right">Sent g</th><th class="right">Returned g</th><th>Result</th><th>HUID</th></tr></thead>
    <tbody>
    @foreach($lot->items as $i => $li)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $li->finishedItem->tag_no }}</td>
            <td>{{ $li->finishedItem->design->code }} {{ $li->finishedItem->design->name }}</td>
            <td>{{ $li->finishedItem->size }}</td>
            <td class="num">{{ $li->sent_wt }}</td>
            <td class="num">{{ $li->returned_wt }}</td>
            <td>{{ $li->result }}</td>
            <td><b>{{ $li->huid }}</b></td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="sign"><tr>
    <td><span>Received by AHC</span></td>
    <td class="right"><span>For {{ $tenant->name }}</span></td>
</tr></table>
</body></html>
