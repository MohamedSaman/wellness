@extends('print.layout')

@php($money = fn (int $minor): string => $shop->formatMoney($minor))
@php($qty = fn ($value): string => \App\Support\Quantity::format((float) $value))

@section('title', 'Stock write-off')
@section('number', $writeOff->write_off_number)

@section('content')
    <div class="meta">
        <dl>
            <dt>Location</dt><dd><strong>{{ $writeOff->location->name }}</strong></dd>
            <dt>Reason</dt><dd>{{ $writeOff->reason->label() }}</dd>
        </dl>
        <dl>
            <dt>Write-off no.</dt><dd>{{ $writeOff->write_off_number }}</dd>
            <dt>Date</dt><dd>{{ $writeOff->written_off_at->timezone($shop->timezone ?: config('app.timezone'))->format('d/m/Y H:i') }}</dd>
            <dt>By</dt><dd>{{ $writeOff->user?->name ?? '-' }}</dd>
        </dl>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th><th>Item</th><th>Batch / expiry</th><th class="num">Quantity</th>
                @if ($showCost)<th class="num">Unit cost</th><th class="num">Value</th>@endif
            </tr>
        </thead>
        <tbody>
            @foreach ($writeOff->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->variant->product->name }} {{ $item->variant->name }}<br><span class="small muted">{{ $item->variant->sku }}</span></td>
                    <td>{{ $item->batch_no }}@if ($item->expiry_date)<br><span class="small">exp {{ $item->expiry_date->format('d/m/Y') }}</span>@endif</td>
                    <td class="num">{{ $qty($item->quantity) }} {{ $item->variant->product->unit }}</td>
                    @if ($showCost)
                        <td class="num">{{ $money(\App\Support\UnitCost::toMinor($item->unit_cost)) }} / {{ $item->variant->product->unit }}</td>
                        <td class="num">{{ $money($item->line_total) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($showCost)
        <table class="totals">
            <tr class="grand"><td>Written off at cost</td><td class="num">{{ $money($writeOff->total) }}</td></tr>
        </table>
    @endif

    @if ($writeOff->note)<div class="notes"><strong>Note:</strong> {{ $writeOff->note }}</div>@endif

    <div class="signatures">
        <div>Written off by</div>
        <div>Witnessed by</div>
        <div>Destroyed / disposed on</div>
    </div>
@endsection
