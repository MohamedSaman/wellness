@extends('print.layout')

@php($money = fn (int $minor): string => $shop->formatMoney($minor))
@php($qty = fn ($value): string => \App\Support\Quantity::format((float) $value))
@php($taxed = $receipt->line_tax_total != 0 || $receipt->invoice_tax != 0)

@section('title', $receipt->isReversal() ? 'Goods received reversal' : 'Goods received note')
@section('number', $receipt->grn_number)

@section('content')
    @if ($receipt->isReversal())
        <div class="banner">Reverses {{ $receipt->reversalOf?->grn_number }}: the quantities below were taken back out of stock.</div>
    @endif

    <div class="meta">
        <dl>
            <dt>Supplier</dt>
            <dd>
                <strong>{{ $receipt->supplier->name }}</strong>
                @if ($receipt->supplier->tax_number)<br>Tax no. {{ $receipt->supplier->tax_number }}@endif
            </dd>
            <dt>Invoice</dt><dd>{{ $receipt->supplier_invoice_no ?? '-' }}@if ($receipt->supplier_invoice_date) of {{ $receipt->supplier_invoice_date->format('d/m/Y') }}@endif</dd>
            @if ($receipt->purchaseOrder)<dt>Order</dt><dd>{{ $receipt->purchaseOrder->po_number }}</dd>@endif
        </dl>
        <dl>
            <dt>GRN no.</dt><dd>{{ $receipt->grn_number }}</dd>
            <dt>Received on</dt><dd>{{ $receipt->received_on->format('d/m/Y') }}</dd>
            <dt>Received at</dt><dd>{{ $receipt->location->name }}</dd>
            <dt>Posted</dt><dd>{{ $receipt->posted_at?->timezone($shop->timezone)->format('d/m/Y H:i') }} by {{ $receipt->poster->name ?? '-' }}</dd>
        </dl>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th><th>Item</th><th>Batch / expiry</th><th class="num">Qty</th><th class="num">Free</th>
                <th class="num">Unit cost</th><th class="num">Discount</th>@if ($taxed)<th class="num">Tax</th>@endif<th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($receipt->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->variant->product->name }} {{ $item->variant->name }}<br><span class="small muted">{{ $item->variant->sku }} · {{ $qty($item->base_quantity) }} {{ $item->variant->product->unit }} at {{ $money(\App\Support\UnitCost::toMinor($item->base_unit_cost)) }}</span></td>
                    <td>{{ $item->batch_no ?? '-' }}@if ($item->expiry_date)<br><span class="small">exp {{ $item->expiry_date->format('m/Y') }}</span>@endif</td>
                    <td class="num">{{ $qty($item->quantity) }} {{ $item->unit_name }}</td>
                    <td class="num">{{ (float) $item->free_quantity != 0 ? $qty($item->free_quantity) : '' }}</td>
                    <td class="num">{{ $money($item->unit_cost) }}</td>
                    <td class="num">{{ $item->discount + $item->invoice_discount_share != 0 ? $money($item->discount + $item->invoice_discount_share) : '' }}</td>
                    @if ($taxed)<td class="num">{{ $item->tax + $item->invoice_tax_share != 0 ? $money($item->tax + $item->invoice_tax_share) : '' }}</td>@endif
                    <td class="num">{{ $money($item->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="num">{{ $money($receipt->subtotal) }}</td></tr>
        @if ($receipt->line_discount_total != 0)<tr><td>Line discounts</td><td class="num">−{{ $money(abs($receipt->line_discount_total)) }}</td></tr>@endif
        @if ($receipt->invoice_discount != 0)<tr><td>Invoice discount</td><td class="num">−{{ $money(abs($receipt->invoice_discount)) }}</td></tr>@endif
        @if ($receipt->line_tax_total != 0)<tr><td>Tax on lines</td><td class="num">{{ $money($receipt->line_tax_total) }}</td></tr>@endif
        @if ($receipt->invoice_tax != 0)<tr><td>Invoice tax</td><td class="num">{{ $money($receipt->invoice_tax) }}</td></tr>@endif
        <tr class="grand"><td>Total</td><td class="num">{{ $money($receipt->total) }}</td></tr>
    </table>

    @if ($receipt->notes)<div class="notes"><strong>Notes:</strong> {{ $receipt->notes }}</div>@endif

    <div class="signatures">
        <div>Received by</div>
        <div>Checked by</div>
        <div>Date</div>
    </div>
@endsection
