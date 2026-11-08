<!doctype html>
<html><head><meta charset="utf-8">@include('pdf._style')</head>
<body>
<table class="head"><tr>
    <td>
        <h1>{{ $tenant->name }}</h1>
        <div class="muted">{{ $tenant->address }} @if($tenant->gstin) · GSTIN {{ $tenant->gstin }} @endif</div>
    </td>
    <td class="right">
        <h2>Delivery challan</h2>
        <div><b>{{ $d->challan_no }}</b></div>
        <div class="muted">{{ \Carbon\Carbon::parse($d->date)->format('d M Y') }}</div>
    </td>
</tr></table>

<table style="margin-bottom:10px"><tr>
    <td class="box" style="width:60%">
        <span class="muted">To</span><br>
        <b>{{ $d->retailer->shop_name }}</b><br>
        {{ $d->retailer->address }} {{ $d->retailer->city }}<br>
        @if($d->retailer->gstin) GSTIN {{ $d->retailer->gstin }} @endif
    </td>
    <td class="box">
        @if($d->order)<span class="muted">Order</span> <b>{{ $d->order->order_no }}</b><br>@endif
        <span class="muted">Pieces</span> <b>{{ $d->pieces }}</b><br>
        <span class="muted">Net weight</span> <b>{{ $d->net_wt }} g</b>
    </td>
</tr></table>

<table>
    <thead><tr>
        <th>#</th><th>Tag</th><th>Design</th><th>Karat</th><th>Size</th><th>HUID</th>
        <th class="right">Gross g</th><th class="right">Stone g</th><th class="right">Net g</th><th class="right">Fine g</th>
    </tr></thead>
    <tbody>
    @foreach($d->lines as $i => $line)
        @php $it = $line->item; @endphp
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $it->tag_no }}</td>
            <td>{{ $it->design->code }} {{ $it->design->name }}</td>
            <td>{{ $it->karat->label }}</td>
            <td>{{ $it->size }}</td>
            <td><b>{{ $it->huid ?? '—' }}</b></td>
            <td class="num">{{ $it->gross_wt }}</td>
            <td class="num">{{ $it->stone_wt }}</td>
            <td class="num">{{ $it->net_wt }}</td>
            <td class="num">{{ $it->fine_wt }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td colspan="6">Total</td>
        <td class="num">{{ $d->gross_wt }}</td><td></td>
        <td class="num">{{ $d->net_wt }}</td>
        <td class="num">{{ $d->fine_wt }}</td>
    </tr>
    </tbody>
</table>

@if($d->remarks)<p><span class="muted">Remarks:</span> {{ $d->remarks }}</p>@endif
<p class="muted">Goods sent on approval / for sale. Tax invoice follows from actual weights.</p>

<table class="sign"><tr>
    <td><span>Receiver's signature</span></td>
    <td class="right"><span>For {{ $tenant->name }}</span></td>
</tr></table>
</body></html>
