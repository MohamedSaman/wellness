@extends('print.layout')

@php($qty = fn ($value): string => \App\Support\Quantity::format((float) $value))
@php($tz = $shop->timezone ?: config('app.timezone'))
@php($showSystem = ! $count->blind)

@section('title', 'Stock count sheet')
@section('number', $count->count_number)

@section('content')
    <div class="meta">
        <dl>
            <dt>Location</dt><dd><strong>{{ $count->location->name }}</strong></dd>
            <dt>Covers</dt><dd>{{ $count->coverage() }}</dd>
            <dt>Count</dt><dd>{{ $count->blind ? 'Blind: what the system holds is not shown' : 'With what the system holds' }}</dd>
        </dl>
        <dl>
            <dt>Count no.</dt><dd>{{ $count->count_number }}</dd>
            <dt>Started</dt><dd>{{ $count->started_at->timezone($tz)->format('d/m/Y H:i') }}</dd>
            <dt>By</dt><dd>{{ $count->starter?->name ?? '-' }}</dd>
        </dl>
    </div>

    @if ($count->note)<div class="notes"><strong>Note:</strong> {{ $count->note }}</div>@endif

    <table>
        <thead>
            <tr>
                <th>#</th><th>Shelf / bin</th><th>Item</th><th>Batch / expiry</th>
                @if ($showSystem)<th class="num">System</th>@endif
                <th>Packs</th><th class="num" style="width: 110px">Counted</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                @php($variant = $line->variant)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $variant->bin_location ?? '' }}</td>
                    <td>{{ $variant->product->name }} {{ $variant->name }}<br><span class="small muted">{{ $variant->sku }}</span></td>
                    <td>@if ($line->batch_no !== null){{ $line->batch_no }}@if ($line->expiry_date)<br><span class="small">exp {{ $line->expiry_date->format('m/Y') }}</span>@endif @else - @endif</td>
                    @if ($showSystem)<td class="num">{{ $qty($line->expected_quantity) }} {{ $variant->product->unit }}</td>@endif
                    <td class="small">{{ $variant->units->where('is_active', true)->where('is_base', false)->map(fn ($unit) => \App\Models\VariantUnit::describe($unit->name, (string) $unit->factor))->implode(', ') ?: '' }}</td>
                    <td class="num">____ {{ $variant->product->unit }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="small muted">Count every batch separately. Write down any batch found that is not on the sheet, with its number and expiry, at the end.</p>

    <div class="signatures">
        <div>Counted by</div>
        <div>Checked by</div>
        <div>Date</div>
    </div>
@endsection
