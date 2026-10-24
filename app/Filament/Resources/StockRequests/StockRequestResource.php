<?php

namespace App\Filament\Resources\StockRequests;

use App\Enums\StockRequestStatus;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\StockRequests\Pages\CreateStockRequest;
use App\Filament\Resources\StockRequests\Pages\ListStockRequests;
use App\Filament\Resources\StockRequests\Pages\ViewStockRequest;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StockRequestService;
use App\Support\CurrentShop;
use App\Support\Quantity;
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
 * Stock requests (multi_location, docs/PHARMACY.md §9, branch 10): the OPD
 * asks General Pharmacy for stock; General Pharmacy sends it with a
 * transfer, all at once or in parts. Who sees what: StockRequestPolicy.
 * Cashiers make their requests from the till (/pos/stock-requests); the
 * owner works here.
 */
class StockRequestResource extends PermissionResource
{
    protected static ?string $model = StockRequest::class;

    protected static ?string $slug = 'stock-requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Stock requests';

    protected static ?string $modelLabel = 'stock request';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('multi_location') ?? false) && parent::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        $user = static::user();

        return $user === null
            ? parent::getEloquentQuery()->whereRaw('1 = 0')
            : app(StockRequestService::class)->visibleTo(parent::getEloquentQuery(), $user);
    }

    /** Requests waiting for stock: the badge General Pharmacy works from. */
    public static function getNavigationBadge(): ?string
    {
        $user = static::user();

        if ($user === null || ! static::canAccess()) {
            return null;
        }

        $open = app(StockRequestService::class)->openCount($user);

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Requests waiting for stock';
    }

    /**
     * Locations the user may ask stock for (their allowed locations).
     *
     * @return array<int, string>
     */
    public static function requestingLocations(): array
    {
        $user = static::user();

        return array_filter(
            Location::options(),
            fn (int $id): bool => $user instanceof User && $user->mayUseLocation($id),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public static function form(Schema $schema): Schema
    {
        $shop = fn () => app(CurrentShop::class)->getOrFail();

        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    Select::make('to_location_id')
                        ->label('For (asking)')
                        ->options(fn (): array => static::requestingLocations())
                        ->default(function () use ($shop): ?int {
                            $options = static::requestingLocations();
                            $work = static::user()?->workLocation($shop())->getKey();
                            $default = (int) $shop()->defaultLocation()->getKey();
                            $others = array_diff(array_keys($options), [$default]);

                            return isset($options[$work]) && $work !== $default ? (int) $work : (array_values($others)[0] ?? null);
                        })
                        ->required()
                        ->native(false)
                        ->live(),
                    Select::make('from_location_id')
                        ->label('From (sends the stock)')
                        ->options(fn (Get $get): array => array_diff_key(Location::options(), [(int) $get('to_location_id') => true]))
                        ->default(fn (): int => (int) $shop()->defaultLocation()->getKey())
                        ->required()
                        ->different('to_location_id')
                        ->native(false)
                        ->live(),
                    Textarea::make('note')->rows(2)->maxLength(500)->columnSpanFull()->placeholder('Needed for ..., urgent ...'),
                ]),
            Section::make('Items')
                ->description('Ask in any unit of the item: boxes, strips or loose units.')
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->minItems(1)
                        ->maxItems(StockRequestService::MAX_LINES)
                        ->default([[]])
                        ->columns(4)
                        ->addActionLabel('Add item')
                        ->schema([
                            Select::make('variant_id')
                                ->label('Item')
                                ->required()
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search): array => StockTransferResource::searchItems($search))
                                ->getOptionLabelUsing(fn ($value): ?string => StockTransferResource::itemLabel(ProductVariant::query()->with('product')->find((int) $value)))
                                ->live()
                                ->afterStateUpdated(fn (Set $set) => $set('unit_id', null))
                                ->columnSpan(2),
                            Select::make('unit_id')
                                ->label('Unit')
                                ->options(fn (Get $get): array => StockTransferResource::unitOptions($get('variant_id')))
                                ->placeholder('Base unit'),
                            TextInput::make('quantity')
                                ->numeric()
                                ->required()
                                ->minValue(0.001)
                                ->step(0.001)
                                ->helperText(fn (Get $get): ?string => static::stockHint($get('variant_id'), $get('../../to_location_id'), $get('../../from_location_id'))),
                        ]),
                ]),
        ]);
    }

    /** "Here: 4 Tablet · General Pharmacy: 300 Tablet" */
    public static function stockHint(mixed $variantId, mixed $toId, mixed $fromId): ?string
    {
        $parts = array_filter([
            StockTransferResource::onHandHint($variantId, $toId),
            StockTransferResource::onHandHint($variantId, $fromId),
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['fromLocation', 'toLocation', 'requester'])->withCount('items'))
            ->columns([
                TextColumn::make('request_number')->label('Request')->weight('bold')->searchable(),
                TextColumn::make('requested_at')->label('Asked')->dateTime()->sortable(),
                TextColumn::make('toLocation.name')->label('For'),
                TextColumn::make('fromLocation.name')->label('From'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (StockRequestStatus $state): string => $state->label())
                    ->color(fn (StockRequestStatus $state): string => $state->color()),
                TextColumn::make('items_count')->label('Items'),
                TextColumn::make('requester.name')->label('By')->placeholder('-'),
                TextColumn::make('note')->limit(40)->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'open' => 'Waiting for stock',
                        ...StockRequestStatus::options(),
                    ])
                    ->default('open')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'open' => $query->whereIn('status', StockRequestStatus::openValues()),
                        default => $query->where('status', $data['value']),
                    }),
                SelectFilter::make('to_location_id')->label('For')->options(fn (): array => Location::options()),
            ])
            ->defaultSort('requested_at', 'desc')
            ->recordUrl(fn (StockRequest $record): string => static::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No stock requests')
            ->emptyStateDescription('Requests from the OPD for stock from General Pharmacy show here.');
    }

    public static function infolist(Schema $schema): Schema
    {
        $quantity = fn ($state): string => Quantity::format((float) $state);
        $inUnit = function (StockRequestItem $item, float $base): string {
            $factor = (float) $item->unit_factor;
            $unit = $item->variant->product->unit;

            if ($factor != 1.0 && Quantity::isWhole($base / $factor)) {
                return Quantity::format($base / $factor).' '.$item->unit_name.' ('.Quantity::format($base).' '.$unit.')';
            }

            return Quantity::format($base).' '.$unit;
        };

        return $schema->components([
            Section::make()
                ->columns(4)
                ->schema([
                    TextEntry::make('request_number')->label('Request'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (StockRequestStatus $state): string => $state->label())
                        ->color(fn (StockRequestStatus $state): string => $state->color()),
                    TextEntry::make('toLocation.name')->label('For'),
                    TextEntry::make('fromLocation.name')->label('From'),
                    TextEntry::make('requested_at')->label('Asked')->dateTime(),
                    TextEntry::make('requester.name')->label('By')->placeholder('-'),
                    TextEntry::make('cancelled_at')->label('Cancelled')->dateTime()->visible(fn (StockRequest $record): bool => $record->cancelled_at !== null),
                    TextEntry::make('note')->placeholder('-')->columnSpanFull(),
                ]),
            Section::make('Items')->schema([
                RepeatableEntry::make('items')
                    ->hiddenLabel()
                    ->columns(5)
                    ->schema([
                        TextEntry::make('item')
                            ->label('Item')
                            ->state(fn (StockRequestItem $record): ?string => StockTransferResource::itemLabel($record->variant))
                            ->columnSpan(2),
                        TextEntry::make('quantity')
                            ->label('Asked for')
                            ->formatStateUsing($quantity)
                            ->suffix(fn (StockRequestItem $record): string => ' '.$record->unit_name),
                        TextEntry::make('sent_base_quantity')
                            ->label('Sent')
                            ->state(fn (StockRequestItem $record): string => $inUnit($record, (float) $record->sent_base_quantity)),
                        TextEntry::make('outstanding')
                            ->label('Still to send')
                            ->state(fn (StockRequestItem $record): string => $inUnit($record, $record->outstandingBase()))
                            ->color(fn (StockRequestItem $record): string => $record->outstandingBase() > 0 ? 'warning' : 'success'),
                    ]),
            ]),
            Section::make('Transfers')
                ->visible(fn (StockRequest $record): bool => $record->transfers->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('transfers')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('transfer_number')
                                ->label('Transfer')
                                ->url(fn (StockTransfer $record): string => StockTransferResource::getUrl('view', ['record' => $record])),
                            TextEntry::make('transferred_at')->label('When')->dateTime(),
                            TextEntry::make('user.name')->label('By')->placeholder('-'),
                        ]),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockRequests::route('/'),
            'create' => CreateStockRequest::route('/create'),
            'view' => ViewStockRequest::route('/{record}'),
        ];
    }
}
