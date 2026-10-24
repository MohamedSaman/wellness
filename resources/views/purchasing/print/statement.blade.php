@extends('print.layout')

@php($money = fn (int $minor): string => $shop->formatMoney($minor))

@section('title', 'Supplier statement')
@section('number', $statement['from']->format('d/m/Y').' to '.$statement['to']->format('d/m/Y'))

@section('content')
    <div class="meta">
        <dl>
            <dt>Supplier</dt>
            <dd>
                <strong>{{ $supplier->name }}</strong>
                @if ($supplier->contact_name)<br>Attn: {{ $supplier->contact_name }}@endif
                @if ($supplier->address)<br>{{ $supplier->address }}@endif
                @if ($supplier->phone)<br>{{ $supplier->phone }}@endif
            </dd>
        </dl>
        <dl>
            <dt>Period</dt><dd>{{ $statement['from']->format('d/m/Y') }} to {{ $statement['to']->format('d/m/Y') }}</dd>
            <dt>Payment terms</dt><dd>{{ $supplier->payment_terms_days === null ? '-' : $supplier->payment_terms_days.' days' }}</dd>
            @if ($supplier->tax_number)<dt>Tax no.</dt><dd>{{ $supplier->tax_number }}</dd>@endif
            <dt>Printed</dt><dd>{{ now($shop->timezone)->format('d/m/Y H:i') }}</dd>
        </dl>
    </div>

    <table>
        <thead>
            <tr><th>Date</th><th>Invoice date</th><th>Document</th><th>Details</th><th class="num">Owed</th><th class="num">Paid / credited</th><th class="num">Balance</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $statement['from']->format('d/m/Y') }}</td>
                <td colspan="5"><strong>Balance brought forward</strong></td>
                <td class="num"><strong>{{ $money($statement['opening']) }}</strong></td>
            </tr>
            @forelse ($statement['lines'] as $line)
                <tr>
                    <td>{{ $line['date']->format('d/m/Y') }}</td>
                    <td>{{ $line['invoice_date']?->format('d/m/Y') ?? '' }}</td>
                    <td>{{ $line['document'] }}</td>
                    <td class="small">{{ $line['detail'] ?? '' }}</td>
                    <td class="num">{{ $line['debit'] === 0 ? '' : $money($line['debit']) }}</td>
                    <td class="num">{{ $line['credit'] === 0 ? '' : $money($line['credit']) }}</td>
                    <td class="num">{{ $money($line['balance']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Nothing in this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="small muted" style="margin-top: -6px">Date: received (goods received notes), paid, returned, the invoice date (opening invoices, so the balance brought forward matches the supplier's statement), or as of (opening balances). Invoice date: the supplier's own, where there is one.</p>

    <table class="totals">
        <tr class="grand"><td>Balance at {{ $statement['to']->format('d/m/Y') }}</td><td class="num">{{ $money($statement['closing']) }}</td></tr>
        <tr><td class="muted small" colspan="2">Positive: owed to {{ $supplier->name }}. Negative: credit with the supplier.</td></tr>
    </table>

    <h3 style="font-size: 14px; margin: 24px 0 8px">Unpaid invoices at {{ $today->format('d/m/Y') }}</h3>
    <table>
        <thead>
            <tr><th>Document</th><th>Invoice</th><th>Invoice date</th><th>Due</th><th class="num">Days overdue</th><th class="num">Amount</th><th class="num">Outstanding</th></tr>
        </thead>
        <tbody>
            @forelse ($open as $payable)
                @php($late = $payable->daysOverdue($today))
                <tr>
                    <td>{{ $payable->documentLabel() }}</td>
                    <td>{{ $payable->invoice_no ?? '-' }}</td>
                    <td>{{ $payable->invoice_date?->format('d/m/Y') ?? '-' }}</td>
                    <td>{{ $payable->due_on->format('d/m/Y') }}</td>
                    <td class="num">{{ $late > 0 ? $late : '' }}</td>
                    <td class="num">{{ $money($payable->amount) }}</td>
                    <td class="num">{{ $money($payable->outstanding()) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">None.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Unpaid invoices</td><td class="num">{{ $money($open->sum(fn ($payable) => $payable->outstanding())) }}</td></tr>
        @if ($credit > 0)<tr><td>Less credit not yet allocated</td><td class="num">{{ $money(-$credit) }}</td></tr>@endif
    </table>
@endsection
