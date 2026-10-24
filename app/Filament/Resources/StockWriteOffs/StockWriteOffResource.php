<?php

namespace App\Filament\Resources\StockWriteOffs;

use App\Enums\AdjustmentReason;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Resources\StockWriteOffs\Pages\ListStockWriteOffs;
use App\Filament\Resources\StockWriteOffs\Pages\ViewStockWriteOff;
use App\Filament\Support\ShopMoney;
use App\Models\Location;
use App\Models\StockWriteOff;
use App\Models\StockWriteOffItem;
use App\Support\CurrentShop;
use App\Support\Quantity;
use App\Support\UnitCost;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Write-off notes (pharmacy, docs/PHARMACY.md §9, branch 10): batches
 * written off from the Expiry screen, per location, with the reason and
 * the value at batch cost. Read only, printable for signatures.
 */
class StockWriteOffResource extends PermissionResource
{
    protected static ?string $model = StockWriteOff::class;

    protected static ?string $slug = 'write-offs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMinus;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Write-offs';

    protected static ?string $modelLabel = 'write-off';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false) && parent::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $canSeeCost = fn (): bool => static::user()?->can('cost.view') ?? false;

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['location', 'user'])->withCount('items'))
            ->columns([
                TextColumn::make('write_off_number')->label('Write-off')->weight('bold')->searchable(),
                TextColumn::make('written_off_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('location.name')->label('Location')->visible(fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('multi_location')),
                TextColumn::make('reason')->formatStateUsing(fn (AdjustmentReason $state): string => $state->label()),
                TextColumn::make('items_count')->label('Batches'),
                TextColumn::make('total')->label('Value at cost')->formatStateUsing(fn (int $state): ?string => ShopMoney::format($state))->alignEnd()->visible($canSeeCost),
                TextColumn::make('user.name')->label('By')->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('location_id')->label('Location')->options(fn (): array => Location::options()),
                SelectFilter::make('reason')->options(AdjustmentReason::writeOffOptions()),
            ])
            ->defaultSort('written_off_at', 'desc')
            ->recordUrl(fn (StockWriteOff $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        $quantity = fn ($state): string => Quantity::format((float) $state);
        $canSeeCost = fn (): bool => static::user()?->can('cost.view') ?? false;

        return $schema->components([
            Section::make()
                ->columns(4)
                ->schema([
                    TextEntry::make('write_off_number')->label('Write-off'),
                    TextEntry::make('written_off_at')->label('When')->dateTime(),
                    TextEntry::make('location.name')->label('Location'),
                    TextEntry::make('reason')->formatStateUsing(fn (AdjustmentReason $state): string => $state->label()),
                    TextEntry::make('user.name')->label('By')->placeholder('-'),
                    TextEntry::make('total')->label('Value at cost')->formatStateUsing(fn (int $state): ?string => ShopMoney::format($state))->visible($canSeeCost),
                    TextEntry::make('note')->placeholder('-')->columnSpan(2),
                ]),
            Section::make('Batches')->schema([
                RepeatableEntry::make('items')
                    ->hiddenLabel()
                    ->columns(5)
                    ->schema([
                        TextEntry::make('item')
                            ->label('Item')
                            ->state(fn (StockWriteOffItem $record): ?string => StockTransferResource::itemLabel($record->variant))
                            ->columnSpan(2),
                        TextEntry::make('batch')
                            ->label('Batch')
                            ->state(fn (StockWriteOffItem $record): string => $record->batch_no.($record->expiry_date === null ? '' : ' · exp '.$record->expiry_date->format('m/Y'))),
                        TextEntry::make('quantity')->formatStateUsing($quantity)->suffix(fn (StockWriteOffItem $record): string => ' '.$record->variant->product->unit),
                        TextEntry::make('line_total')
                            ->label('Value at cost')
                            ->formatStateUsing(fn (int $state): ?string => ShopMoney::format($state))
                            ->helperText(fn (StockWriteOffItem $record): string => ShopMoney::format(UnitCost::toMinor($record->unit_cost)).' per '.$record->variant->product->unit)
                            ->visible($canSeeCost),
                    ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockWriteOffs::route('/'),
            'view' => ViewStockWriteOff::route('/{record}'),
        ];
    }
}
