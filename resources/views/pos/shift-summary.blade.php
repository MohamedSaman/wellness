{{-- A shift's summary for the cashier who ran it (docs/PHARMACY.md §9, branch 11): takings, payments and the drawer. --}}
@extends('print.layout')

@php($money = fn (?int $minor): string => $minor === null ? '-' : $shop->formatMoney($minor))

@section('title', 'Shift summary')
@section('number', $shift->device?->code.' · '.$openedAt)

@section('content')
    <div class="meta">
        <dl>
            <dt>Till</dt><dd>{{ $shift->device?->code }} {{ $shift->device?->name }}</dd>
            @if ($shift->device?->location)<dt>Counter</dt><dd>{{ $shift->device->location->name }}</dd>@endif
            <dt>Cashier</dt><dd>{{ $shift->user?->name }}</dd>
        </dl>
        <dl>
            <dt>Opened</dt><dd>{{ $openedAt }}</dd>
            <dt>Closed</dt><dd>{{ $closedAt ?? 'Still open' }}</dd>
            <dt>Printed</dt><dd>{{ now($shop->timezone)->format('d/m/Y H:i') }}</dd>
        </dl>
    </div>

    <table class="totals" style="margin-left: 0; width: 360px" data-test="shift-takings">
        <tr><td>Bills</td><td class="num">{{ number_format($summary['bills']) }}</td></tr>
        <tr><td>Sales</td><td class="num">{{ $money($summary['gross']) }}</td></tr>
        <tr><td>Refunds ({{ $summary['refunds_count'] }})</td><td class="num">{{ $money(-$summary['refunds']) }}</td></tr>
        <tr class="grand"><td>Net sales</td><td class="num">{{ $money($summary['net']) }}</td></tr>
        <tr><td class="muted">Voided bills ({{ $summary['voids'] }})</td><td class="num muted">{{ $money($summary['voids_total']) }}</td></tr>
    </table>

    <table>
        <thead><tr><th>Payment method</th><th class="num">Taken</th></tr></thead>
        <tbody>
            @forelse ($summary['payments'] as $payment)
                <tr><td>{{ $payment['label'] }}</td><td class="num">{{ $money($payment['amount']) }}</td></tr>
            @empty
                <tr><td colspan="2" class="muted">No payments.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="small muted">Cash is net of change given back.</p>

    <table class="totals" style="margin-left: 0; width: 360px" data-test="shift-cash">
        <tr><td>Opening float</td><td class="num">{{ $money($summary['cash']['opening']) }}</td></tr>
        <tr><td>Cash sales</td><td class="num">{{ $money($summary['cash']['sales']) }}</td></tr>
        <tr><td>Cash refunds</td><td class="num">{{ $money(-$summary['cash']['refunds']) }}</td></tr>
        <tr class="grand"><td>Expected in the drawer</td><td class="num">{{ $money($summary['cash']['expected']) }}</td></tr>
        <tr><td>Counted</td><td class="num">{{ $money($summary['cash']['counted']) }}</td></tr>
        <tr><td>Difference</td><td class="num">{{ $money($summary['cash']['difference']) }}</td></tr>
    </table>

    <div class="signatures">
        <div>Cashier</div>
        <div>Checked by</div>
        <div>Date</div>
    </div>
@endsection
