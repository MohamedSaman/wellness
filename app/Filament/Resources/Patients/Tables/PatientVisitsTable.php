<?php

namespace App\Filament\Resources\Patients\Tables;

use App\Filament\Resources\Patients\Actions\PatientQuickView;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\ShopMoney;
use App\Models\Sale;
use App\Services\PatientVisitsService;
use App\Support\PatientAge;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The Visits tab of the Patients list (docs/PHARMACY.md, branch 21): one
 * row per bill behind the list, sharing its filters, search and summary
 * (PatientResource::listState()). Clicking a row opens the patient's quick
 * view; the invoice opens the bill. The amount only with reports.view, like
 * the summary's money; what was given to whoever may see the list.
 */
final class PatientVisitsTable
{
    /** The medicines and other items are cut to this many characters in the row; the tooltip has them all. */
    public const GIVEN_LIMIT = 80;

    public static function configure(Table $table, HasTable $livewire): Table
    {
        $service = app(PatientVisitsService::class);
        $timezone = fn (): string => PatientResource::timezone();

        return PatientResource::listState($table)
            ->query(fn (): Builder => $service->query(PatientResource::filtersOf($livewire)))
            ->defaultSort('reported_at', 'desc')
            ->columns([
                // The time under the date, the age under the name, the location under the type: the row fits beside the sidebar at 1280 px.
                TextColumn::make('reported_at')
                    ->label('Time')
                    ->date('d/m/Y', $timezone)
                    ->description(fn (Sale $record): string => $record->reported_at?->setTimezone($timezone())->format('H:i') ?? '')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('sales.reported_at', $direction === 'asc' ? 'asc' : 'desc')
                        ->orderBy('sales.id', $direction === 'asc' ? 'asc' : 'desc')),
                TextColumn::make('patient.name')
                    ->label('Patient')
                    ->weight('medium')
                    ->description(fn (Sale $record): ?string => PatientAge::format($record->patient_age_months) === null ? null : PatientAge::format($record->patient_age_months).' at the visit')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereIn('sales.patient_id', PatientResource::searchPatients($search)))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->join('patients as visit_patients', 'visit_patients.id', '=', 'sales.patient_id')
                        ->orderBy('visit_patients.name', $direction === 'desc' ? 'desc' : 'asc'))
                    ->wrap(),
                TextColumn::make('kind')
                    ->label('Type · location')
                    ->badge()
                    ->state(fn (Sale $record): string => PatientVisitsService::kind($record))
                    ->description(fn (Sale $record): ?string => $record->location?->name)
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, 'Exchange') => 'warning',
                        $state === 'OPD' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('invoice_no')
                    ->label('Invoice')
                    ->url(fn (Sale $record): ?string => SaleResource::canView($record) ? SaleResource::getUrl('view', ['record' => $record]) : null)
                    ->color(fn (Sale $record): ?string => SaleResource::canView($record) ? 'primary' : null),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->tooltip('Less refunds')
                    ->state(fn (Sale $record): int => PatientVisitsService::net($record))
                    ->formatStateUsing(fn (int $state): string => ShopMoney::format($state))
                    ->alignEnd()
                    ->visible(fn (): bool => auth()->user()?->can('reports.view') ?? false)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw('(sales.grand_total - sales.refunded_total) '.($direction === 'asc' ? 'asc' : 'desc'))),
                // Two short lines: the medicines, then the other items; each cut, the tooltip has them all.
                TextColumn::make('medicines')
                    ->label('Given')
                    ->state(fn (Sale $record): array => self::lines($service->given($record)))
                    ->formatStateUsing(fn (string $state): string => Str::limit($state, self::GIVEN_LIMIT))
                    ->listWithLineBreaks()
                    ->placeholder('Nothing')
                    ->tooltip(fn (Sale $record): ?string => self::tooltip($service->given($record)))
                    ->wrap()
                    ->extraAttributes(['data-test' => 'visit-given']),
                TextColumn::make('cashier.name')
                    ->label('Cashier')
                    ->wrap(),
            ])
            ->recordActions([PatientQuickView::make()])
            ->recordAction(PatientQuickView::NAME)
            ->recordUrl(null)
            ->emptyStateHeading('No visits')
            ->emptyStateDescription('No bill with a patient matches the filters.');
    }

    /**
     * The row's lines: the medicines, then "Other: …" (either left out when
     * there is none).
     *
     * @param  array{medicines: list<string>, other: list<string>}  $given
     * @return list<string>
     */
    public static function lines(array $given): array
    {
        return array_values(array_filter([
            $given['medicines'] === [] ? null : implode(', ', $given['medicines']),
            $given['other'] === [] ? null : 'Other: '.implode(', ', $given['other']),
        ]));
    }

    /**
     * Every line, when the row had to cut them.
     *
     * @param  array{medicines: list<string>, other: list<string>}  $given
     */
    private static function tooltip(array $given): ?string
    {
        if (collect(self::lines($given))->every(fn (string $line): bool => mb_strlen($line) <= self::GIVEN_LIMIT)) {
            return null;
        }

        return implode(' · ', array_filter([
            $given['medicines'] === [] ? null : 'Medicines: '.implode('; ', $given['medicines']),
            $given['other'] === [] ? null : 'Other items: '.implode('; ', $given['other']),
        ]));
    }
}
