@extends('print.layout')

{{-- The A4 invoice of a sale: SaleInvoiceController. The receipt's payload, with the shop's receipt choices. --}}
@php($money = fn (?int $minor): string => $minor === null ? '' : \App\Support\Money::format($minor, (int) $receiptShop['decimals']))
@php($date = fn (?string $value): string => \App\Services\Receipts\ReceiptLayout::date((string) $value))
@php($options = $receiptShop['receipt'])
@php($opd = $sale['billType'] === 'opd')
@php($services = $opd ? array_values(array_filter($sale['items'], fn ($item) => ! $item['isOpdMedicine'])) : $sale['items'])
@php($medicines = $opd ? array_values(array_filter($sale['items'], fn ($item) => $item['isOpdMedicine'])) : [])
@php($taxOn = $sale['taxRate'] > 0)
@php($batchText = function (array $item) use ($options): array {
    $lines = [];
    foreach ($item['batches'] ?? [] as $batch) {
        $parts = [];
        if ($options['showBatch']) {
            $parts[] = $batch['batchNo'] === null ? 'Batch to be confirmed' : 'Batch '.$batch['batchNo'];
        }
        if ($options['showExpiry'] && $batch['expiry'] !== null) {
            $parts[] = 'exp '.$batch['expiry'];
        }
        if ($parts !== [] && count($item['batches']) > 1) {
            $parts[] = '× '.$batch['quantity'];
        }
        if ($parts !== []) {
            $lines[] = implode(' · ', $parts);
        }
    }
    return $lines;
})
@php($rx = fn (array $item): array => $options['showPrescriber'] ? array_values(array_filter([
    filled($item['prescriber'] ?? null) ? 'Prescriber: '.$item['prescriber'] : null,
    filled($item['prescriptionRef'] ?? null) ? 'Rx ref: '.$item['prescriptionRef'] : null,
])) : [])
@php($quantity = fn (array $item): string => trim($item['quantity'].' '.($item['unitName'] ?? '')))

@section('title', 'Invoice')
@section('number', $sale['invoiceNo'])

@section('content')
    @if ($sale['status'] === 'voided')
        <div class="banner">VOIDED: this sale was cancelled.</div>
    @endif

    <div class="meta">
        <dl>
            @if (filled($sale['customerName']))
                <dt>{{ $shop->hasModule('pharmacy') ? 'Patient' : 'Customer' }}</dt>
                <dd><strong>{{ $sale['customerName'] }}</strong>@if (filled($sale['customerPhone']))<br>{{ $sale['customerPhone'] }}@endif</dd>
            @endif
            @if (filled($receiptShop['header']))
                <dt></dt><dd class="notes">{{ $receiptShop['header'] }}</dd>
            @endif
        </dl>
        <dl>
            <dt>Bill no.</dt><dd>{{ $sale['invoiceNo'] }}</dd>
            <dt>Date</dt><dd>{{ $date($sale['soldAt']) }}</dd>
            @if ($sale['till'])
                <dt>Till</dt><dd>{{ $sale['till']['code'] }}@if (filled($sale['till']['name']) && $sale['till']['name'] !== $sale['till']['code']) {{ $sale['till']['name'] }}@endif</dd>
                @if (filled($sale['till']['location']))<dt>Location</dt><dd>{{ $sale['till']['location'] }}</dd>@endif
            @endif
            <dt>Cashier</dt><dd>{{ $sale['cashier'] }}</dd>
        </dl>
    </div>

    <table>
        <thead>
            <tr><th>#</th><th>Item</th><th class="num">Quantity</th><th class="num">Unit price</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
            @foreach ($services as $item)
                <tr data-test="invoice-line">
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        {{ \App\Services\Receipts\ReceiptLayout::itemName($item) }}
                        @foreach ([...$batchText($item), ...$rx($item)] as $detail)<br><span class="small muted">{{ $detail }}</span>@endforeach
                        @if ($item['returnedQuantity'])<br><span class="small">Returned {{ $item['returnedQuantity'] }}</span>@endif
                    </td>
                    <td class="num">{{ $quantity($item) }}</td>
                    <td class="num">{{ $money($item['unitPrice']) }}</td>
                    <td class="num">{{ $money($item['lineTotal']) }}@if ($taxOn && $item['taxExempt']) E @endif</td>
                </tr>
                @if (($item['discount'] ?? 0) > 0)
                    <tr><td></td><td class="small">Discount</td><td></td><td></td><td class="num">-{{ $money($item['discount']) }}</td></tr>
                @endif
            @endforeach
            @if ($medicines !== [])
                <tr data-test="invoice-medicines">
                    <td>{{ count($services) + 1 }}</td>
                    <td>
                        <strong>Medicines</strong>
                        @if ($sale['opdReceiptMedicines'] !== 'totals')
                            @foreach ($medicines as $item)
                                <br>{{ \App\Services\Receipts\ReceiptLayout::itemName($item) }} × {{ $quantity($item) }}
                                @foreach ([...$batchText($item), ...$rx($item)] as $detail)<br><span class="small muted">&nbsp;&nbsp;{{ $detail }}</span>@endforeach
                            @endforeach
                        @endif
                    </td>
                    <td></td>
                    <td></td>
                    <td class="num">{{ $money($sale['medicinesTotal']) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <table class="totals">
        @if ($sale['discountTotal'] > 0 || $taxOn)
            <tr><td>Subtotal</td><td class="num">{{ $money($sale['subtotal']) }}</td></tr>
        @endif
        @if ($sale['discountTotal'] > 0)
            <tr><td>Discount</td><td class="num">-{{ $money($sale['discountTotal']) }}</td></tr>
        @endif
        @if ($taxOn)
            <tr><td>Tax {{ rtrim(rtrim(number_format($sale['taxRate'], 2, '.', ''), '0'), '.') }}%{{ $sale['pricesIncludeTax'] ? ' (incl.)' : '' }}</td><td class="num">{{ $money($sale['taxTotal']) }}</td></tr>
        @endif
        <tr class="grand"><td>Total {{ $receiptShop['currency'] }}</td><td class="num">{{ $money($sale['grandTotal']) }}</td></tr>
        @foreach ($sale['payments'] as $payment)
            <tr><td>{{ $payment['method'] }}@if (filled($payment['reference'])) <span class="small muted">ref {{ $payment['reference'] }}</span>@endif</td><td class="num">{{ $money($payment['amount']) }}</td></tr>
        @endforeach
        <tr><td>Change</td><td class="num">{{ $money($sale['changeDue']) }}</td></tr>
        @if ($sale['refundedTotal'] > 0)
            <tr><td>Refunded</td><td class="num">-{{ $money($sale['refundedTotal']) }}</td></tr>
        @endif
    </table>

    @if ($taxOn && collect($sale['items'])->contains('taxExempt', true))
        <p class="small">E = tax exempt</p>
    @endif

    @if (filled($receiptShop['footer']))
        <div class="notes">{{ $receiptShop['footer'] }}</div>
    @endif

    <p class="small muted">Printed {{ now()->timezone($shop->timezone)->format('d/m/Y H:i') }}@if ($printedBy) by {{ $printedBy }}@endif. A copy of bill {{ $sale['invoiceNo'] }}.</p>
@endsection
