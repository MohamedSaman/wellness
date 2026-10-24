<?php

namespace App\Filament\Resources\StockTransfers;

use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\Resources\StockTransfers\Pages\ListStockTransfers;
use App\Filament\Resources\StockTransfers\Pages\ViewStockTransfer;
use App\Filament\Support\ShopMoney;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\StockTransferItemBatch;
use App\Models\User;
use App\Models\VariantUnit;
use App\Support\CurrentShop;
use App\Support\Quantity;
use App\Support\UnitCost;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Transfers between locations (multi_location). Saving a transfer posts it
 * through StockTransferService: paired ledger movements at the source
 * location's average cost, never more than the source has on hand. Posted
 * transfers are read-only. Making one needs stock.transfer (owner).
 * A batch-tracked item moves the batch chosen on the line, or first expiry
 * first out; the transfer shows each batch moved.
 */
class StockTransferResource extends PermissionResource
{
    protected static ?string $model = StockTransfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Transfers';

    protected static ?string $modelLabel = 'transfer';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('multi_location') ?? false) && parent::canAccess();
    }

    private static function workLocationId(): ?int
    {
        $user = static::user();

        return $user instanceof User ? (int) $user->workLocation(app(CurrentShop::class)->getOrFail())->getKey() : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    Select::make('from_location_id')
                        ->label('From')
                        ->options(fn (): array => Location::options())
                        ->default(fn (): ?int => self::workLocationId())
                        ->required()
                        ->native(false)
                        ->live(),
                    Select::make('to_location_id')
                        ->label('To')
                        ->options(fn (Get $get): array => array_diff_key(Location::options(), [(int) $get('from_location_id') => true]))
                        ->required()
                        ->different('from_location_id')
                        ->native(false),
                    Textarea::make('note')->rows(2)->maxLength(500)->columnSpanFull()->placeholder('Why, who asked for it ...'),
                ]),
            Section::make('Items')
                ->description('Enter each quantity in any unit of the item: boxes, strips or loose units. It is moved at the source location\'s average cost.')
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->minItems(1)
                        ->maxItems(200)
                        ->default([[]])
                        ->columns(5)
                        ->addActionLabel('Add item')
                        ->schema([
                            Select::make('variant_id')
                                ->label('Item')
                                ->required()
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search): array => static::searchItems($search))
                                ->getOptionLabelUsing(fn ($value): ?string => static::itemLabel(ProductVariant::query()->with('product')->find((int) $value)))
                                ->live()
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('unit_id', null);
                                    $set('batch_id', null);
                                })
                                ->columnSpan(2),
                            Select::make('unit_id')
                                ->label('Unit')
                                ->options(fn (Get $get): array => static::unitOptions($get('variant_id')))
                                ->placeholder('Base unit')
                                ->live(),
                            TextInput::make('quantity')
                                ->numeric()
                                ->required()
                                ->minValue(0.001)
                                ->step(0.001)
                                ->helperText(fn (Get $get): ?string => static::onHandHint($get('variant_id'), $get('../../from_location_id'))),
                            Select::make('batch_id')
                                ->label('Batch')
                                ->options(fn (Get $get): array => static::batchOptions($get('variant_id'), $get('../../from_location_id')))
                                ->placeholder('First expiry first out')
                                ->helperText('Choose one to move a particular batch, e.g. expired stock into quarantine.')
                                ->visible(fn (Get $get): bool => static::tracksBatches($get('variant_id'))),
                        ]),
                ]),
        ]);
    }

    /** @return array<int, string> */
    public static function searchItems(string $search): array
    {
        return ProductVariant::query()
            ->with('product')
            ->whereHas('product', fn (Builder $product) => $product->where('track_stock', true))
            ->where(fn (Builder $query) => $query
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('barcode', $search)
                ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%")))
            ->orderBy('sku')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (ProductVariant $variant): array => [(int) $variant->getKey() => (string) static::itemLabel($variant)])
            ->all();
    }

    public static function itemLabel(?ProductVariant $variant): ?string
    {
        return $variant === null ? null : trim("{$variant->sku} — {$variant->product->name} ".($variant->name ?? ''));
    }

    /** @return array<int, string> */
    public static function unitOptions(mixed $variantId): array
    {
        if (! filled($variantId)) {
            return [];
        }

        return VariantUnit::query()
            ->where('product_variant_id', (int) $variantId)
            ->where('is_active', true)
            ->orderByDesc('is_base')->orderBy('sort_order')->orderBy('id')
            ->get()
            ->mapWithKeys(fn (VariantUnit $unit): array => [(int) $unit->getKey() => $unit->is_base ? $unit->name : "{$unit->name} (".Quantity::format((float) $unit->factor).')'])
            ->all();
    }

    public static function tracksBatches(mixed $variantId): bool
    {
        return filled($variantId) && (bool) ProductVariant::query()->with('product')->find((int) $variantId)?->product->tracksBatches();
    }

    /** @return array<int, string> the item's batches with stock at a location, soonest expiry first */
    public static function batchOptions(mixed $variantId, mixed $locationId): array
    {
        if (! filled($variantId) || ! filled($locationId)) {
            return [];
        }

        return StockBatch::query()
            ->where('product_variant_id', (int) $variantId)
            ->where('location_id', (int) $locationId)
            ->where('is_unallocated', false)
            ->where('quantity', '>', 0)
            ->fefo()
            ->get()
            ->mapWithKeys(fn (StockBatch $batch): array => [(int) $batch->getKey() => $batch->label().' — '.Quantity::format((float) $batch->quantity)])
            ->all();
    }

    public static function onHandHint(mixed $variantId, mixed $locationId): ?string
    {
        if (! filled($variantId) || ! filled($locationId)) {
            return null;
        }

        $variant = ProductVariant::query()->with('product')->find((int) $variantId);
        $location = Location::query()->find((int) $locationId);

        if ($variant === null || $location === null) {
            return null;
        }

        $onHand = StockLevel::query()->where('product_variant_id', $variant->getKey())->where('location_id', $location->getKey())->value('quantity');

        return 'On hand at '.$location->name.': '.Quantity::format((float) ($onHand ?? 0)).' '.$variant->product->unit;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['fromLocation', 'toLocation', 'user'])->withCount('items'))
            ->columns([
                TextColumn::make('transfer_number')->label('Transfer')->weight('bold')->searchable(),
                TextColumn::make('transferred_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('fromLocation.name')->label('From'),
                TextColumn::make('toLocation.name')->label('To'),
                TextColumn::make('items_count')->label('Lines'),
                TextColumn::make('user.name')->label('By')->placeholder('-'),
                TextColumn::make('note')->limit(40)->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('from_location_id')->label('From')->options(fn (): array => Location::options()),
                SelectFilter::make('to_location_id')->label('To')->options(fn (): array => Location::options()),
            ])
            ->defaultSort('transferred_at', 'desc')
            ->recordUrl(fn (StockTransfer $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        $quantity = fn ($state): string => Quantity::format((float) $state);
        $canSeeCost = fn (): bool => static::user()?->can('cost.view') ?? false;

        return $schema->components([
            Section::make()
                ->columns(4)
                ->schema([
                    TextEntry::make('transfer_number')->label('Transfer'),
                    TextEntry::make('transferred_at')->label('When')->dateTime(),
                    TextEntry::make('fromLocation.name')->label('From'),
                    TextEntry::make('toLocation.name')->label('To'),
                    TextEntry::make('user.name')->label('By')->placeholder('-'),
                    TextEntry::make('note')->placeholder('-')->columnSpan(3),
                ]),
            Section::make('Items')->schema([
                RepeatableEntry::make('items')
                    ->hiddenLabel()
                    ->columns(5)
                    ->schema([
                        TextEntry::make('item')
                            ->label('Item')
                            ->state(fn (StockTransferItem $record): ?string => static::itemLabel($record->variant))
                            ->columnSpan(2),
                        TextEntry::make('quantity')->formatStateUsing($quantity)->suffix(fn (StockTransferItem $record): string => ' '.$record->unit_name),
                        TextEntry::make('base_quantity')
                            ->label('Base units')
                            ->formatStateUsing($quantity)
                            ->suffix(fn (StockTransferItem $record): string => ' '.$record->variant->product->unit),
                        TextEntry::make('unit_cost')
                            ->label('Cost per base unit')
                            ->tooltip('The source location\'s average cost when the stock moved')
                            ->formatStateUsing(fn ($state): ?string => ShopMoney::format(UnitCost::toMinor((string) $state)))
                            ->visible($canSeeCost),
                        TextEntry::make('batches_moved')
                            ->label('Batches')
                            ->state(fn (StockTransferItem $record): ?string => $record->batches->isEmpty() ? null : $record->batches
                                ->map(fn (StockTransferItemBatch $batch): string => $batch->label().' x '.Quantity::format((float) $batch->quantity))
                                ->implode(', '))
                            ->visible(fn (StockTransferItem $record): bool => $record->batches->isNotEmpty())
                            ->columnSpanFull(),
                    ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockTransfers::route('/'),
            'create' => CreateStockTransfer::route('/create'),
            'view' => ViewStockTransfer::route('/{record}'),
        ];
    }
}
