@extends('print.layout')

@php($qty = fn ($value): string => \App\Support\Quantity::format((float) $value))
@php($tz = $shop->timezone ?: config('app.timezone'))

@section('title', 'Stock request')
@section('number', $stockRequest->request_number)

@section('content')
    <div class="meta">
        <dl>
            <dt>For</dt><dd><strong>{{ $stockRequest->toLocation->name }}</strong></dd>
            <dt>From</dt><dd>{{ $stockRequest->fromLocation->name }}</dd>
            <dt>Status</dt><dd>{{ $stockRequest->status->label() }}</dd>
        </dl>
        <dl>
            <dt>Request no.</dt><dd>{{ $stockRequest->request_number }}</dd>
            <dt>Asked</dt><dd>{{ $stockRequest->requested_at->timezone($tz)->format('d/m/Y H:i') }}</dd>
            <dt>By</dt><dd>{{ $stockRequest->requester?->name ?? '-' }}</dd>
            @if ($stockRequest->transfers->isNotEmpty())
                <dt>Transfers</dt><dd>{{ $stockRequest->transfers->pluck('transfer_number')->implode(', ') }}</dd>
            @endif
        </dl>
    </div>

    @if ($stockRequest->note)<div class="notes"><strong>Note:</strong> {{ $stockRequest->note }}</div>@endif

    <table>
        <thead>
            <tr><th>#</th><th>Item</th><th class="num">Asked for</th><th class="num">Sent</th><th class="num">Still to send</th></tr>
        </thead>
        <tbody>
            @foreach ($stockRequest->items as $item)
                @php($unit = $item->variant->product->unit)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->variant->product->name }} {{ $item->variant->name }}<br><span class="small muted">{{ $item->variant->sku }}@if ($item->variant->bin_location) · {{ $item->variant->bin_location }}@endif</span></td>
                    <td class="num">{{ $qty($item->quantity) }} {{ $item->unit_name }}@if ((float) $item->unit_factor != 1.0)<br><span class="small muted">({{ $qty($item->base_quantity) }} {{ $unit }})</span>@endif</td>
                    <td class="num">{{ $qty($item->sent_base_quantity) }} {{ $unit }}</td>
                    <td class="num"><strong>{{ $qty($item->outstandingBase()) }} {{ $unit }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($picks !== [])
        <h3 style="margin: 24px 0 8px">Pick list at {{ $stockRequest->fromLocation->name }}</h3>
        <p class="small muted" style="margin-top: 0">The batches a transfer would take today, first expiry first out (expired and blocked batches are never picked). Sending posts the transfer.</p>
        <table>
            <thead>
                <tr><th>Item</th><th>Shelf / bin</th><th class="num">To send</th><th class="num">On hand</th><th>Batches to pick</th><th class="num">Picked</th></tr>
            </thead>
            <tbody>
                @foreach ($picks as $pick)
                    @php($unit = $pick['item']->variant->product->unit)
                    <tr>
                        <td>{{ $pick['item']->variant->product->name }}<br><span class="small muted">{{ $pick['item']->variant->sku }}</span></td>
                        <td>{{ $pick['item']->variant->bin_location ?? '-' }}</td>
                        <td class="num">{{ $qty($pick['outstanding']) }} {{ $unit }}</td>
                        <td class="num">{{ $qty($pick['on_hand']) }}</td>
                        <td>
                            @forelse ($pick['batches'] as $batch)
                                {{ $batch['label'] }} × {{ $qty($batch['quantity']) }}<br>
                            @empty
                                -
                            @endforelse
                            @if ($pick['short'] > 0)<span class="small"><strong>Short {{ $qty($pick['short']) }} {{ $unit }}</strong></span>@endif
                        </td>
                        <td class="num">&nbsp;</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="signatures">
        <div>Picked by</div>
        <div>Received at {{ $stockRequest->toLocation->name }} by</div>
        <div>Date</div>
    </div>
@endsection
