@extends('print.layout')

@php($money = fn (int $minor): string => $shop->formatMoney($minor))
@php($qty = fn ($value): string => \App\Support\Quantity::format((float) $value))

@section('title', 'Return to supplier')
@section('number', $return->return_number)

@section('content')
    <div class="meta">
        <dl>
            <dt>To</dt>
            <dd>
                <strong>{{ $return->supplier->name }}</strong>
                @if ($return->supplier->contact_name)<br>Attn: {{ $return->supplier->contact_name }}@endif
                @if ($return->supplier->address)<br>{{ $return->supplier->address }}@endif
                @if ($return->supplier->phone)<br>{{ $return->supplier->phone }}@endif
            </dd>
        </dl>
        <dl>
            <dt>Return no.</dt><dd>{{ $return->return_number }}</dd>
            <dt>Date</dt><dd>{{ $return->returned_on->format('d/m/Y') }}</dd>
            <dt>From</dt><dd>{{ $return->location->name }}</dd>
            <dt>Reason</dt><dd>{{ $return->reason->label() }}</dd>
            @if ($return->credit_note_no)<dt>Credit note</dt><dd>{{ $return->credit_note_no }}</dd>@endif
        </dl>
    </div>

    <table>
        <thead>
            <tr><th>#</th><th>Item</th><th>Batch / expiry</th><th class="num">Quantity</th><th class="num">Unit cost</th><th class="num">Credit</th></tr>
        </thead>
        <tbody>
            @foreach ($return->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->variant->product->name }} {{ $item->variant->name }}<br><span class="small muted">{{ $item->variant->sku }}</span></td>
                    <td>{{ $item->batch_no ?? '-' }}@if ($item->expiry_date)<br><span class="small">exp {{ $item->expiry_date->format('m/Y') }}</span>@endif</td>
                    <td class="num">{{ $qty($item->quantity) }} {{ $item->unit_name }}@if ((float) $item->unit_factor != 1.0)<br><span class="small muted">({{ $qty($item->base_quantity) }} {{ $item->variant->product->unit }})</span>@endif</td>
                    <td class="num">{{ $money(\App\Support\UnitCost::toMinor($item->unit_cost)) }} / {{ $item->variant->product->unit }}</td>
                    <td class="num">{{ $money($item->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr class="grand"><td>Credit expected</td><td class="num">{{ $money($return->total) }}</td></tr>
    </table>

    @if ($return->notes)<div class="notes"><strong>Notes:</strong> {{ $return->notes }}</div>@endif

    <div class="signatures">
        <div>Released by</div>
        <div>Received for the supplier by</div>
        <div>Date</div>
    </div>
@endsection
