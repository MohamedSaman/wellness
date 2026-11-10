<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Enums\BillType;
use App\Enums\SaleStatus;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\ShopMoney;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\CurrentShop;
use App\Support\PatientAge;
use App\Support\Quantity;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The patient's history (docs/PHARMACY.md, branch 13): every bill, OPD
 * consultations and pharmacy sales, at every till and location, newest
 * first, with the medicines dispensed and the age at that visit, as the
 * bill recorded them. Read only.
 */
class SalesRelationManager extends RelationManager
{
    protected static string $relationship = 'sales';

    protected static ?string $title = 'History';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $timezone = fn (): string => app(CurrentShop::class)->getOrFail()->timezone ?: 'UTC';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['items', 'device', 'location', 'cashier']))
            ->defaultSort('sold_at', 'desc')
            ->columns([
                TextColumn::make('sold_at')->label('Date')->dateTime('d/m/Y H:i', $timezone)->sortable(),
                TextColumn::make('invoice_no')
                    ->label('Bill')
                    ->url(fn (Sale $record): string => SaleResource::getUrl('view', ['record' => $record]))
                    ->color('primary'),
                TextColumn::make('bill_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (BillType $state): string => $state === BillType::Opd ? 'OPD' : 'Pharmacy')
                    ->color(fn (BillType $state): string => $state === BillType::Opd ? 'info' : 'gray'),
                TextColumn::make('where')
                    ->label('Till / location')
                    ->state(fn (Sale $record): string => $record->device->code.' · '.$record->location->name),
                TextColumn::make('patient_age_months')
                    ->label('Age')
                    ->formatStateUsing(fn (?int $state): ?string => PatientAge::format($state))
                    ->placeholder('-'),
                TextColumn::make('medicines')
                    ->label('Medicines')
                    ->state(fn (Sale $record): array => $record->items
                        ->filter(fn (SaleItem $item): bool => $item->drug_schedule !== null)
                        ->map(fn (SaleItem $item): string => trim($item->description.' '.(str_contains($item->description, (string) $item->strength) ? '' : (string) $item->strength))
                            .' × '.Quantity::format((float) $item->quantity).' '.($item->unit_name ?? ''))
                        ->values()
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder('None')
                    ->wrap(),
                TextColumn::make('prescriber')
                    ->label('Prescriber')
                    ->state(fn (Sale $record): ?string => $record->items->pluck('prescriber')->filter()->unique()->implode(', ') ?: null)
                    ->placeholder('-'),
                TextColumn::make('grand_total')->label('Total')->formatStateUsing(fn ($state): ?string => ShopMoney::format((int) $state))->alignEnd(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SaleStatus $state): string => ucwords(str_replace('_', ' ', $state->value)))
                    ->color(fn (SaleStatus $state): string => match ($state) {
                        SaleStatus::Completed => 'success',
                        SaleStatus::PartiallyReturned => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('cashier.name')->label('Cashier')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('bill_type')
                    ->label('Type')
                    ->options([BillType::Opd->value => 'OPD', BillType::Standard->value => 'Pharmacy']),
            ]);
    }
}
