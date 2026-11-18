{{-- A shift's summary for the cashier who ran it (docs/PHARMACY.md §9, branch 11): takings, payments and the drawer. --}}
@extends('print.layout')

@php($money = fn (?int $minor): string => $minor === null ? '-' : $shop->formatMoney($minor))

@section('title', 'Shift summary')
@section('number', $shift->device?->code.' · '.$openedAt)

@section('content')
    @if ($reprint ?? null)
        {{-- A logged reprint (branch 17), marked as the receipt printer's reprints are. --}}
        <div class="banner" style="text-align: center" data-test="shift-reprint">
            *** REPRINT ***<br>
            <span style="font-weight: 400">Reprinted {{ $reprint['printedAt'] }} by {{ $reprint['printedBy'] }}</span>
        </div>
    @endif
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
        @if (($summary['no_receipt_count'] ?? 0) > 0)
            {{-- Returns without a receipt (branch 18): part of the refunds above. --}}
            <tr data-test="shift-no-receipt"><td class="muted">&nbsp;&nbsp;of which without receipt ({{ $summary['no_receipt_count'] }})</td><td class="num muted">{{ $money(-$summary['no_receipt_refunds']) }}</td></tr>
        @endif
        @if (($summary['exchanges'] ?? 0) > 0 || ($summary['till_returns'] ?? 0) > 0)
            {{-- Returns and exchanges at the till (branch 19): counts; the money is in the refunds and payments. --}}
            <tr data-test="shift-exchanges"><td class="muted">Exchanges / returns at the till</td><td class="num muted">{{ $summary['exchanges'] }} / {{ $summary['till_returns'] }}</td></tr>
        @endif
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
        @if (array_key_exists('paid_out', $summary['cash']))
            {{-- Cash movements (branch 18). --}}
            <tr><td>Paid out</td><td class="num">{{ $money(-$summary['cash']['paid_out']) }}</td></tr>
            @if (array_key_exists('doctor_payments', $summary))
                <tr><td>Doctor payments</td><td class="num">{{ $money(-$summary['cash']['doctor_payments']) }}</td></tr>
            @endif
            <tr><td>Paid in</td><td class="num">{{ $money($summary['cash']['paid_in']) }}</td></tr>
        @endif
        <tr class="grand"><td>Expected in the drawer</td><td class="num">{{ $money($summary['cash']['expected']) }}</td></tr>
        <tr><td>Counted</td><td class="num">{{ $money($summary['cash']['counted']) }}</td></tr>
        <tr><td>Difference</td><td class="num">{{ $money($summary['cash']['difference']) }}</td></tr>
    </table>

    @if (array_key_exists('movements', $summary))
        <h3 style="margin-top: 16px">Paid out and paid in</h3>
        <table data-test="shift-movements">
            <thead><tr><th>No.</th><th>Time</th><th>Kind</th><th>For</th><th>By</th><th>Note</th><th class="num">Amount</th></tr></thead>
            <tbody>
                @forelse ($summary['movements'] as $movement)
                    <tr>
                        <td>{{ $movement['number'] }}</td>
                        <td>{{ substr($movement['at'], 11) }}</td>
                        <td>{{ $movement['label'] }}</td>
                        <td>{{ $movement['category'] }}</td>
                        <td>{{ $movement['user'] }}</td>
                        <td>{{ $movement['note'] }}</td>
                        <td class="num">{{ $money($movement['type'] === 'paid_in' ? $movement['amount'] : -$movement['amount']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">Nothing paid out or in.</td></tr>
                @endforelse
            </tbody>
        </table>
        @if (($summary['voided_movements'] ?? 0) > 0)
            <p class="small muted">Voided cash movements ({{ $summary['voided_movements'] }}) are not counted.</p>
        @endif
    @endif

    @if (array_key_exists('doctor_payments', $summary))
        <h3 style="margin-top: 16px">Doctor payments</h3>
        <table class="totals" style="margin-left: 0; width: 360px" data-test="shift-doctor-payments">
            @forelse ($summary['doctor_payments'] as $payment)
                <tr><td>{{ $payment['doctor'] }}@if ($payment['payments'] > 1) ({{ $payment['payments'] }})@endif</td><td class="num">{{ $money($payment['amount']) }}</td></tr>
            @empty
                <tr><td class="muted">No doctor paid in this shift.</td><td></td></tr>
            @endforelse
            @if (count($summary['doctor_payments']) > 1)
                <tr class="grand"><td>Total</td><td class="num">{{ $money($summary['cash']['doctor_payments']) }}</td></tr>
            @endif
        </table>
    @endif

    <div class="signatures">
        <div>Cashier</div>
        <div>Checked by</div>
        <div>Date</div>
    </div>
@endsection
