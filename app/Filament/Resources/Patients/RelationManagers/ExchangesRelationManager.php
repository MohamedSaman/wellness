<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\ShopMoney;
use App\Models\Exchange;
use App\Models\SaleItem;
use App\Services\ExchangeService;
use App\Support\CurrentShop;
use App\Support\Quantity;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The patient's returns and exchanges at the till (docs/PHARMACY.md, branch
 * 19): each links the bill the items came from and the new bill, with what
 * came back (and where it went), what was given instead, the difference
 * (paid by the patient or refunded) and why. Read only.
 */
class ExchangesRelationManager extends RelationManager
{
    protected static string $relationship = 'exchanges';

    protected static ?string $title = 'Exchanges';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $timezone = fn (): string => app(CurrentShop::class)->getOrFail()->timezone ?: 'UTC';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'originalSale', 'newSale.items', 'saleReturn.items.saleItem', 'saleReturn.items.location',
                'noReceiptReturn.items.location', 'doctor', 'user', 'device',
            ]))
            ->defaultSort('exchanged_at', 'desc')
            ->emptyStateHeading('No returns or exchanges')
            ->columns([
                TextColumn::make('exchanged_at')->label('Date')->dateTime('d/m/Y H:i', $timezone)->sortable(),
                TextColumn::make('number')
                    ->label('Exchange')
                    ->badge()
                    ->state(fn (Exchange $record): string => $record->number.' · '.($record->isPlainReturn() ? 'Return' : 'Exchange'))
                    ->color(fn (Exchange $record): string => $record->isPlainReturn() ? 'gray' : 'info'),
                TextColumn::make('original')
                    ->label('From bill')
                    ->state(fn (Exchange $record): string => $record->originalSale->invoice_no ?? 'No bill ('.$record->noReceiptReturn?->number.')')
                    ->url(fn (Exchange $record): ?string => $record->originalSale === null ? null : SaleResource::getUrl('view', ['record' => $record->originalSale]))
                    ->color('primary'),
                TextColumn::make('returned')
                    ->label('Returned')
                    ->state(fn (Exchange $record): array => app(ExchangeService::class)->returnedLines($record))
                    ->listWithLineBreaks()
                    ->wrap(),
                TextColumn::make('new_bill')
                    ->label('New bill')
                    ->state(fn (Exchange $record): ?string => $record->newSale?->invoice_no)
                    ->url(fn (Exchange $record): ?string => $record->newSale === null ? null : SaleResource::getUrl('view', ['record' => $record->newSale]))
                    ->color('primary')
                    ->placeholder('-'),
                TextColumn::make('new_items')
                    ->label('Given instead')
                    ->state(fn (Exchange $record): array => $record->newSale === null ? [] : $record->newSale->items
                        ->map(fn (SaleItem $item): string => $item->description.' × '.Quantity::format((float) $item->quantity).' '.($item->unit_name ?? ''))
                        ->values()
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder('-')
                    ->wrap(),
                TextColumn::make('difference')
                    ->label('Difference')
                    ->state(fn (Exchange $record): string => $record->cash_refunded > 0
                        ? 'Refunded '.ShopMoney::format($record->cash_refunded)
                        : 'Paid '.ShopMoney::format($record->patient_paid))
                    ->description(fn (Exchange $record): string => 'Credit '.ShopMoney::format($record->credit_total))
                    ->alignEnd(),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->state(fn (Exchange $record): string => $record->reason->label().(blank($record->note) ? '' : ': '.$record->note))
                    ->wrap(),
                TextColumn::make('doctor.name')->label('Doctor')->placeholder('-'),
                TextColumn::make('user.name')->label('Cashier')->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
