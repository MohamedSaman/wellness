@extends('print.layout')

@php($money = fn (int $minor): string => $shop->formatMoney($minor))
@php($qty = fn ($value): string => \App\Support\Quantity::format((float) $value))

@section('title', 'Purchase order')
@section('number', $order->po_number)

@section('content')
    @if ($order->status === \App\Enums\PurchaseOrderStatus::Cancelled)
        <div class="banner">Cancelled on {{ $order->cancelled_at?->timezone($shop->timezone)->format('d/m/Y') }}</div>
    @elseif ($order->status === \App\Enums\PurchaseOrderStatus::Draft)
        <div class="banner">Draft: not yet sent</div>
    @endif

    <div class="meta">
        <dl>
            <dt>To</dt>
            <dd>
                <strong>{{ $order->supplier->name }}</strong>
                @if ($order->supplier->contact_name)<br>Attn: {{ $order->supplier->contact_name }}@endif
                @if ($order->supplier->address)<br>{{ $order->supplier->address }}@endif
                @if ($order->supplier->phone)<br>{{ $order->supplier->phone }}@endif
                @if ($order->supplier->email)<br>{{ $order->supplier->email }}@endif
            </dd>
        </dl>
        <dl>
            <dt>Order no.</dt><dd>{{ $order->po_number }}</dd>
            <dt>Date</dt><dd>{{ ($order->sent_at ?? $order->created_at)?->timezone($shop->timezone)->format('d/m/Y') }}</dd>
            <dt>Deliver to</dt><dd>{{ $order->location->name }}</dd>
            @if ($order->expected_on)<dt>Expected by</dt><dd>{{ $order->expected_on->format('d/m/Y') }}</dd>@endif
            @if ($order->supplier->payment_terms_days !== null)<dt>Terms</dt><dd>{{ $order->supplier->payment_terms_days }} days</dd>@endif
        </dl>
    </div>

    <table>
        <thead>
            <tr><th>#</th><th>Item</th><th class="num">Quantity</th><th class="num">Unit cost</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->variant->product->name }} {{ $item->variant->name }}<br><span class="small muted">{{ $item->variant->sku }}</span></td>
                    <td class="num">{{ $qty($item->quantity) }} {{ $item->unit_name }}@if ((float) $item->unit_factor != 1.0)<br><span class="small muted">({{ $qty($item->base_quantity) }} {{ $item->variant->product->unit }})</span>@endif</td>
                    <td class="num">{{ $money($item->unit_cost) }}</td>
                    <td class="num">{{ $money($item->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr class="grand"><td>Expected total</td><td class="num">{{ $money($order->total) }}</td></tr>
    </table>

    @if ($order->notes)<div class="notes"><strong>Notes:</strong> {{ $order->notes }}</div>@endif

    <div class="signatures">
        <div>Ordered by</div>
        <div>Authorised by</div>
        <div>Date</div>
    </div>
@endsection
