{{--
    A patient's bills within the list's filters (PatientQuickView, branch 21):
    newest first, each with its time, kind, location, invoice, amount net of
    refunds (reports.view) and what was given.
--}}
@php
    use App\Filament\Resources\Sales\SaleResource;
    use App\Filament\Support\ShopMoney;
    use App\Services\PatientVisitsService;
@endphp
<div class="pos-quick-view" data-test="patient-quick-view">
    @forelse ($bills as $bill)
        @php
            $given = $service->given($bill);
            $kind = PatientVisitsService::kind($bill);
        @endphp
        <article class="pos-quick-view-bill" data-test="quick-view-bill">
            <header class="pos-quick-view-bill-head">
                <span class="pos-quick-view-time">{{ $bill->reported_at->setTimezone($timezone)->format('d/m/Y H:i') }}</span>
                <x-filament::badge :color="str_starts_with($kind, 'Exchange') ? 'warning' : ($kind === 'OPD' ? 'info' : 'gray')" size="sm">{{ $kind }}</x-filament::badge>
                <span class="pos-quick-view-muted">{{ $bill->location?->name }}</span>
                @if ($money)
                    <span class="pos-quick-view-amount">{{ ShopMoney::format(PatientVisitsService::net($bill)) }}</span>
                @endif
            </header>
            <div class="pos-quick-view-muted">
                @if (SaleResource::canView($bill))
                    <a href="{{ SaleResource::getUrl('view', ['record' => $bill]) }}" class="pos-quick-view-link">{{ $bill->invoice_no }}</a>
                @else
                    {{ $bill->invoice_no }}
                @endif
                @if ($bill->cashier)
                    · {{ $bill->cashier->name }}
                @endif
            </div>
            <dl class="pos-quick-view-given">
                <dt>Medicines</dt>
                <dd>
                    @forelse ($given['medicines'] as $line)
                        <div>{{ $line }}</div>
                    @empty
                        <span class="pos-quick-view-muted">None</span>
                    @endforelse
                </dd>
                @if ($given['other'] !== [])
                    <dt>Other items</dt>
                    <dd>
                        @foreach ($given['other'] as $line)
                            <div>{{ $line }}</div>
                        @endforeach
                    </dd>
                @endif
            </dl>
        </article>
    @empty
        <p class="pos-quick-view-muted">No bills within these filters.</p>
    @endforelse

    @if ($more > 0)
        <p class="pos-quick-view-muted" data-test="quick-view-more">
            And {{ number_format($more) }} more {{ $more === 1 ? 'bill' : 'bills' }}: open the full page to see them all.
        </p>
    @endif
</div>
