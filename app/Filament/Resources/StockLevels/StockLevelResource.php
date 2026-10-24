<?php

namespace App\Filament\Resources\StockLevels;

use App\Enums\AdjustmentReason;
use App\Exceptions\PosException;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\StockBatches\StockBatchResource;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Support\ShopMoney;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Services\Data\BatchInput;
use App\Services\ProductCatalogService;
use App\Services\StockService;
use App\Support\CurrentShop;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitCost;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Current stock per variant. Read from the cached stock_levels; every change
 * goes through StockService (Stock In, Adjust), never a direct update.
 *
 * With multi_location: a Location filter narrows on hand, average cost, last
 * cost, reorder level and value to one location (each location has its own
 * average cost); without it they are the whole shop's. Stock In, Adjust and
 * Revalue ask which location, starting at the user's default location, and
 * a Reorder level action sets a location's own reorder level.
 *
 * Batch-tracked items (pharmacy): Stock In asks for the batch number and
 * expiry on the pack; Adjust counts one batch at a time (or a batch found on
 * the shelf that is not on record).
 */
class StockLevelResource extends PermissionResource
{
    protected static ?string $model = ProductVariant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Stock levels';

    protected static ?string $modelLabel = 'stock level';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('product')
            ->whereHas('product', fn (Builder $product) => $product->where('track_stock', true));
    }

    public static function multiLocation(): bool
    {
        return app(CurrentShop::class)->getOrFail()->hasModule('multi_location');
    }

    /** The location picked in the table's Location filter, if any (multi_location only). */
    public static function locationFilter(mixed $livewire): ?int
    {
        $value = $livewire instanceof HasTable ? ($livewire->tableFilters['location']['value'] ?? null) : null;

        if (! ctype_digit((string) $value) || ! static::multiLocation()) {
            return null;
        }

        return Location::query()->whereKey((int) $value)->exists() ? (int) $value : null;
    }

    /**
     * On hand (and the stock levels behind it) for the whole shop, or for one location.
     *
     * @param  Builder<ProductVariant>  $query
     * @return Builder<ProductVariant>
     */
    public static function withStock(Builder $query, ?int $locationId): Builder
    {
        $atLocation = fn ($levels) => $locationId === null ? $levels : $levels->where('location_id', $locationId);

        return $query
            ->with(['stockLevels' => $atLocation])
            ->withSum(['stockLevels as on_hand' => $atLocation], 'quantity');
    }

    /** The location a stock action starts at: the user's default location, else the shop's. */
    public static function workLocation(): Location
    {
        $shop = app(CurrentShop::class)->getOrFail();

        return static::user()?->workLocation($shop) ?? $shop->defaultLocation();
    }

    /** The location chosen in a stock action's form, or the work location when there is no picker. */
    public static function chosenLocation(mixed $id): Location
    {
        if (! filled($id) || ! static::multiLocation()) {
            return static::workLocation();
        }

        return Location::query()->whereKey((int) $id)->where('is_active', true)->first()
            ?? throw new PosException('Choose an active location.');
    }

    /** The location picked in a form, or the work location while nothing valid is picked. */
    public static function chosenLocationOrWork(mixed $id): Location
    {
        try {
            return static::chosenLocation($id);
        } catch (PosException) {
            return static::workLocation();
        }
    }

    public static function levelAt(ProductVariant $record, Location $location): ?StockLevel
    {
        return StockLevel::query()->where('product_variant_id', $record->getKey())->where('location_id', $location->getKey())->first();
    }

    private static function locationSelect(): Select
    {
        return Select::make('location_id')
            ->label('Location')
            ->options(fn (): array => Location::options())
            ->required()
            ->selectablePlaceholder(false)
            ->native(false)
            ->live()
            ->visible(fn (): bool => static::multiLocation());
    }

    public static function table(Table $table): Table
    {
        $canSeeCost = fn (): bool => static::user()?->can('cost.view') ?? false;

        $quantity = fn ($state): string => rtrim(rtrim(number_format((float) $state, 3), '0'), '.');
        $onHand = fn (ProductVariant $record): float => (float) $record->getAttribute('on_hand');

        return $table
            ->modifyQueryUsing(fn (Builder $query, HasTable $livewire): Builder => static::withStock($query, static::locationFilter($livewire)))
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->description(fn (ProductVariant $record): ?string => $record->name)
                    ->wrap()
                    ->extraAttributes(['style' => 'min-width: 9rem'])
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $q) => $q->where('sku', 'like', "%{$search}%")
                            ->orWhere('barcode', $search)
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', "%{$search}%")->orWhere('part_number', 'like', "%{$search}%"))
                    )),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->limit(12)
                    ->tooltip(fn (ProductVariant $record): ?string => mb_strlen($record->sku) > 12 ? $record->sku : null),
                TextColumn::make('bin_location')->label('Shelf / bin')->placeholder('-')->toggleable(),
                TextColumn::make('on_hand')
                    ->label('On hand')
                    ->formatStateUsing($quantity)
                    ->weight(fn (ProductVariant $record): ?string => $onHand($record) < 0 ? 'bold' : null)
                    ->color(fn (ProductVariant $record, HasTable $livewire): ?string => $onHand($record) < 0
                        || (static::reorderLevel($record, $livewire) > 0 && $onHand($record) <= static::reorderLevel($record, $livewire)) ? 'danger' : null)
                    ->icon(fn (ProductVariant $record): ?Heroicon => $onHand($record) < 0 ? Heroicon::ExclamationTriangle : null)
                    ->tooltip(fn (ProductVariant $record): ?string => $onHand($record) < 0 ? 'Stock below zero — do a stock count' : null)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('reorder_level')
                    ->label('Reorder at')
                    ->state(fn (ProductVariant $record, HasTable $livewire): float => static::reorderLevel($record, $livewire))
                    ->formatStateUsing($quantity)
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('cost_price')
                    ->label('Avg cost')
                    ->tooltip(fn (): string => static::multiLocation()
                        ? 'Weighted average cost of the stock on hand: at the location in the filter, or blended across locations'
                        : 'Weighted average cost of the stock on hand')
                    // At one location: that location's own average.
                    ->state(fn (ProductVariant $record, HasTable $livewire): int => static::locationFilter($livewire) === null
                        ? (int) $record->cost_price
                        : UnitCost::toMinor($record->stockLevels->first()->average_cost ?? (int) $record->cost_price))
                    ->formatStateUsing(fn ($state): ?string => ShopMoney::format((int) $state))
                    ->alignEnd()
                    ->visible($canSeeCost)
                    ->toggleable(),
                TextColumn::make('last_cost_price')
                    ->label('Last cost')
                    ->tooltip('Unit cost of the latest delivery')
                    ->state(function (ProductVariant $record, HasTable $livewire): ?int {
                        if (static::locationFilter($livewire) === null) {
                            return $record->last_cost_price;
                        }

                        $last = $record->stockLevels->first()?->last_cost;

                        return $last === null ? null : UnitCost::toMinor($last);
                    })
                    ->formatStateUsing(fn ($state): ?string => $state === null ? null : ShopMoney::format((int) $state))
                    ->placeholder('-')
                    ->alignEnd()
                    ->visible($canSeeCost)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('stock_value')
                    ->label('Value')
                    // Stock below zero is a count to fix, not a negative value.
                    ->state(fn (ProductVariant $record): ?string => ShopMoney::format(static::stockValue($record)))
                    ->tooltip(fn (): ?string => static::multiLocation() ? 'Each location\'s stock at its own average cost' : null)
                    ->alignEnd()
                    ->visible($canSeeCost),
            ])
            ->filters([
                SelectFilter::make('location')
                    ->label('Location')
                    ->options(fn (): array => Location::options())
                    ->placeholder('All locations')
                    // Applied in modifyQueryUsing: it changes what on hand means, not which rows show.
                    ->query(fn (Builder $query): Builder => $query)
                    ->visible(fn (): bool => static::multiLocation()),
                Filter::make('low_stock')
                    ->label('Low stock')
                    ->toggle()
                    ->query(fn (Builder $query, HasTable $livewire): Builder => $query->scopes(['lowStock' => [static::locationFilter($livewire)]])),
                Filter::make('negative_stock')
                    ->label('Negative stock')
                    ->toggle()
                    ->query(fn (Builder $query, HasTable $livewire): Builder => $query->scopes(['negativeStock' => [static::locationFilter($livewire)]])),
            ])
            ->defaultSort('sku')
            // Clicking a row opens its stock history; the actions stay in view: Stock in plus a ⋮ menu.
            ->recordUrl(fn (ProductVariant $record): string => static::historyUrl($record))
            ->recordActions([
                static::stockInAction(),
                ActionGroup::make([
                    static::adjustAction(),
                    Action::make('batches')
                        ->label('Batches')
                        ->icon(Heroicon::OutlinedRectangleStack)
                        ->visible(fn (ProductVariant $record): bool => $record->product->tracksBatches())
                        ->url(fn (ProductVariant $record): string => static::batchesUrl($record)),
                    static::revalueAction(),
                    static::reorderLevelAction(),
                    static::parLevelAction(),
                    Action::make('history')
                        ->label('History')
                        ->icon(Heroicon::OutlinedClock)
                        ->url(fn (ProductVariant $record): string => static::historyUrl($record)),
                ])->label('More actions')->tooltip('More actions'),
            ]);
    }

    /**
     * max(on_hand, 0) x average cost for each loaded location (all, or the
     * filtered one), the same rule as the stock value total.
     */
    public static function stockValue(ProductVariant $record): int
    {
        return (int) $record->stockLevels->sum(fn (StockLevel $level): int => UnitCost::times($level->average_cost, max(0.0, (float) $level->quantity)));
    }

    /** The reorder level in force: the filtered location's own, else the item's. */
    public static function reorderLevel(ProductVariant $record, mixed $livewire): float
    {
        $own = static::locationFilter($livewire) === null ? null : $record->stockLevels->first()?->reorder_level;

        return (float) ($own ?? $record->reorder_level);
    }

    public static function historyUrl(ProductVariant $record): string
    {
        return StockMovementResource::getUrl('index', [
            'filters' => ['variant' => ['value' => $record->getKey()]],
        ]);
    }

    public static function batchesUrl(ProductVariant $record): string
    {
        return StockBatchResource::getUrl('index', [
            'filters' => ['variant' => ['value' => $record->getKey()]],
        ]);
    }

    /**
     * "B1234 · exp 03/2027 — 40 on hand" options of an item's batches at a location.
     *
     * @return array<int, string>
     */
    public static function batchOptions(ProductVariant $record, Location $location, bool $withUnallocated = false): array
    {
        return StockBatch::query()
            ->where('product_variant_id', $record->getKey())
            ->where('location_id', $location->getKey())
            ->where(fn (Builder $query) => $query->where('is_unallocated', false)
                ->when($withUnallocated, fn (Builder $q) => $q->orWhere('quantity', '!=', 0)))
            ->orderBy('is_unallocated')
            ->fefo()
            ->get()
            ->mapWithKeys(fn (StockBatch $batch): array => [(int) $batch->getKey() => $batch->label().' — '.Quantity::format((float) $batch->quantity).' on hand'])
            ->all();
    }

    /**
     * The batch fields of a receipt or a count of a new batch.
     *
     * @return list<TextInput>
     */
    private static function newBatchFields(\Closure $visible): array
    {
        return [
            TextInput::make('batch_no')
                ->label('Batch no.')
                ->maxLength(50)
                ->required($visible)
                ->visible($visible),
            TextInput::make('expiry')
                ->label('Expiry')
                ->placeholder('03/2027')
                ->helperText('The month or date printed on the pack.')
                ->maxLength(10)
                ->required($visible)
                ->visible($visible),
        ];
    }

    public static function stockInAction(): Action
    {
        $canSeeCost = fn (): bool => static::user()?->can('cost.view') ?? false;

        return Action::make('stockIn')
            ->label('Stock in')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('success')
            ->visible(fn (): bool => static::user()?->can('stock.receive') ?? false)
            ->authorize(fn (): bool => static::user()?->can('stock.receive') ?? false)
            ->modalHeading(fn (ProductVariant $record): string => "Stock in: {$record->sku}")
            // The last delivery's cost is the usual starting point for the next one.
            ->fillForm(fn (ProductVariant $record): array => [
                ...(static::multiLocation() ? ['location_id' => static::workLocation()->getKey()] : []),
                ...($canSeeCost() ? ['unit_cost' => Money::toInputString((int) ($record->last_cost_price ?? $record->cost_price), ShopMoney::decimals())] : []),
            ])
            ->schema([
                self::locationSelect(),
                TextInput::make('quantity')->numeric()->required()->minValue(0.001)->step(0.001)->live(debounce: 300),
                ...self::newBatchFields(fn (ProductVariant $record): bool => $record->product->tracksBatches()),
                Toggle::make('accept_near_expiry')
                    ->label('Accept short expiry')
                    ->helperText('Receive it even though it expires soon (owner only).')
                    ->visible(fn (ProductVariant $record): bool => $record->product->tracksBatches() && (static::user()?->can('batches.manage') ?? false)),
                TextInput::make('unit_cost')
                    ->label(fn (): string => 'Unit cost ('.ShopMoney::currency().')')
                    ->numeric()
                    ->minValue(0)
                    ->live(debounce: 300)
                    ->helperText(fn (Get $get, ProductVariant $record): string => static::newAverageHint($record, $get('quantity'), $get('unit_cost'), $get('location_id')))
                    ->visible($canSeeCost),
                Textarea::make('note')->rows(2)->placeholder('Supplier, delivery note number ...'),
            ])
            ->action(function (array $data, ProductVariant $record, Action $action): void {
                self::run($action, function () use ($data, $record): void {
                    app(StockService::class)->stockIn(
                        $record,
                        static::chosenLocation($data['location_id'] ?? null),
                        (float) $data['quantity'],
                        static::user(),
                        filled($data['unit_cost'] ?? null) ? Money::toMinor($data['unit_cost'], ShopMoney::decimals()) : null,
                        $data['note'] ?? null,
                        batch: $record->product->tracksBatches()
                            ? new BatchInput((string) ($data['batch_no'] ?? ''), (string) ($data['expiry'] ?? ''), (bool) ($data['accept_near_expiry'] ?? false))
                            : null,
                    );
                });

                Notification::make()->title('Stock recorded')->success()->send();
            });
    }

    /**
     * "New average will be: LKR 1,025.00 (now LKR 950.00)" at the location
     * (default: the user's work location), worked out exactly as StockService will.
     */
    public static function newAverageHint(ProductVariant $record, mixed $quantity, mixed $unitCost, mixed $locationId = null): string
    {
        try {
            $location = static::chosenLocation($locationId);
        } catch (PosException) {
            $location = static::workLocation();
        }

        $level = static::levelAt($record, $location);
        $current = app(StockService::class)->averageCost($record, $location);
        $now = ' (now '.ShopMoney::format(UnitCost::toMinor($current)).')';

        if (! is_numeric($quantity) || (float) $quantity <= 0 || ! is_numeric($unitCost) || (float) $unitCost < 0) {
            return 'Enter the quantity and unit cost to see the new average cost'.$now.'.';
        }

        $average = UnitCost::weightedAverage((float) ($level->quantity ?? 0), $current, (float) $quantity, Money::toMinor((string) $unitCost, ShopMoney::decimals()));

        return 'New average will be: '.ShopMoney::format(UnitCost::toMinor($average)).$now;
    }

    public static function revalueAction(): Action
    {
        $canManage = fn (): bool => static::user()?->can('cost.manage') ?? false;

        return Action::make('revalue')
            ->label('Revalue cost')
            ->icon(Heroicon::OutlinedScale)
            ->visible($canManage)
            ->authorize($canManage)
            ->modalHeading(fn (ProductVariant $record): string => "Revalue cost: {$record->sku}")
            ->modalDescription(fn (): string => static::multiLocation()
                ? 'Sets the average cost at one location by hand. Past sales keep the cost they were sold at. The change and its reason go to the audit log.'
                : 'Sets the average cost by hand. Past sales keep the cost they were sold at. The change and its reason go to the audit log.')
            ->fillForm(fn (ProductVariant $record): array => [
                ...(static::multiLocation() ? ['location_id' => static::workLocation()->getKey()] : []),
                'new_cost' => self::averageInput($record, static::workLocation()),
            ])
            ->schema([
                self::locationSelect()
                    ->afterStateUpdated(fn (mixed $state, Set $set, ProductVariant $record) => filled($state)
                        ? $set('new_cost', self::averageInput($record, static::chosenLocation($state)))
                        : null),
                TextInput::make('new_cost')
                    ->label(fn (): string => 'New average cost ('.ShopMoney::currency().')')
                    ->numeric()
                    ->required()
                    ->minValue(0),
                Textarea::make('reason')->required()->rows(2)->maxLength(500)->placeholder('Supplier credit note, costing mistake ...'),
            ])
            ->action(function (array $data, ProductVariant $record, Action $action): void {
                self::run($action, function () use ($data, $record): void {
                    app(ProductCatalogService::class)->revalueCost(
                        $record,
                        Money::toMinor($data['new_cost'], ShopMoney::decimals()),
                        $data['reason'],
                        static::user(),
                        static::chosenLocation($data['location_id'] ?? null),
                    );
                });

                Notification::make()->title('Average cost updated')->success()->send();
            });
    }

    public static function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label('Adjust')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('warning')
            ->visible(fn (): bool => static::user()?->can('stock.adjust') ?? false)
            ->authorize(fn (): bool => static::user()?->can('stock.adjust') ?? false)
            ->modalHeading(fn (ProductVariant $record): string => "Adjust stock: {$record->sku}")
            ->modalDescription(fn (ProductVariant $record): string => $record->product->tracksBatches()
                ? 'Count one batch at a time: choose the batch and enter what you counted. The difference is recorded as an adjustment.'
                : 'Enter the quantity you actually counted. The difference is recorded as an adjustment.')
            ->fillForm(fn (ProductVariant $record): array => [
                ...(static::multiLocation() ? ['location_id' => static::workLocation()->getKey()] : []),
                'counted_quantity' => $record->product->tracksBatches() ? null : (float) (static::levelAt($record, static::workLocation())->quantity ?? 0),
            ])
            ->schema([
                self::locationSelect()
                    ->afterStateUpdated(function (mixed $state, Set $set, ProductVariant $record): void {
                        if (! filled($state)) {
                            return;
                        }

                        $set('batch_id', null);
                        $set('counted_quantity', $record->product->tracksBatches() ? null : (float) (static::levelAt($record, static::chosenLocation($state))->quantity ?? 0));
                    }),
                Select::make('batch_id')
                    ->label('Batch')
                    ->options(fn (Get $get, ProductVariant $record): array => static::batchOptions($record, static::chosenLocationOrWork($get('location_id')), withUnallocated: true) + ['new' => 'Another batch, not on record'])
                    ->required(fn (ProductVariant $record): bool => $record->product->tracksBatches())
                    ->visible(fn (ProductVariant $record): bool => $record->product->tracksBatches())
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (mixed $state, Set $set) => $set('counted_quantity', ctype_digit((string) $state)
                        ? (float) (StockBatch::query()->whereKey((int) $state)->value('quantity') ?? 0)
                        : null)),
                ...self::newBatchFields(fn (Get $get, ProductVariant $record): bool => $record->product->tracksBatches() && $get('batch_id') === 'new'),
                TextInput::make('counted_quantity')->label('Counted quantity')->numeric()->required()->minValue(0)->step(0.001),
                Select::make('reason')
                    ->options(AdjustmentReason::options())
                    ->required()
                    ->native(false)
                    ->live(),
                Textarea::make('note')
                    ->rows(2)
                    ->maxLength(500)
                    ->required(fn (Get $get): bool => $get('reason') === AdjustmentReason::Other->value)
                    ->helperText(fn (Get $get): string => $get('reason') === AdjustmentReason::Other->value
                        ? 'Required: say what happened.'
                        : 'Optional detail, e.g. which delivery or where it was found.'),
            ])
            ->action(function (array $data, ProductVariant $record, Action $action): void {
                $movement = null;

                self::run($action, function () use ($data, $record, &$movement): void {
                    $location = static::chosenLocation($data['location_id'] ?? null);

                    if (! $record->product->tracksBatches()) {
                        $movement = app(StockService::class)->adjustTo(
                            $record,
                            $location,
                            (float) $data['counted_quantity'],
                            AdjustmentReason::from($data['reason']),
                            static::user(),
                            $data['note'] ?? null,
                        );

                        return;
                    }

                    $batch = ($data['batch_id'] ?? null) === 'new'
                        ? new BatchInput((string) ($data['batch_no'] ?? ''), (string) ($data['expiry'] ?? ''))
                        : StockBatch::query()->where('product_variant_id', $record->getKey())->whereKey((int) ($data['batch_id'] ?? 0))->first()
                            ?? throw new PosException('Choose the batch you counted.');

                    $movement = app(StockService::class)->adjustBatchTo(
                        $record,
                        $location,
                        $batch,
                        (float) $data['counted_quantity'],
                        AdjustmentReason::from($data['reason']),
                        static::user(),
                        $data['note'] ?? null,
                    );
                });

                Notification::make()
                    ->title($movement === null ? 'Count matches the system, nothing to adjust' : 'Stock adjusted')
                    ->success()
                    ->send();
            });
    }

    /** The location's average cost as a form value. */
    private static function averageInput(ProductVariant $record, Location $location): string
    {
        return Money::toInputString(UnitCost::toMinor(app(StockService::class)->averageCost($record, $location)), ShopMoney::decimals());
    }

    /**
     * A location's own reorder level (multi_location; blank uses the item's)
     * and its par level, what reorder suggestions top it up to.
     */
    public static function reorderLevelAction(): Action
    {
        $canManage = fn (): bool => (static::user()?->can('products.manage') ?? false) && static::multiLocation();
        $ownLevel = fn (ProductVariant $record, Location $location): ?float => ($own = static::levelAt($record, $location)?->reorder_level) === null ? null : (float) $own;
        $parLevel = fn (ProductVariant $record, Location $location): ?float => ($par = static::levelAt($record, $location)?->par_level) === null ? null : (float) $par;

        return Action::make('reorderLevel')
            ->label('Reorder and par levels')
            ->icon(Heroicon::OutlinedBellAlert)
            ->visible($canManage)
            ->authorize($canManage)
            ->modalHeading(fn (ProductVariant $record): string => "Reorder level: {$record->sku}")
            ->modalDescription(fn (ProductVariant $record): string => 'The item\'s own reorder level is '.Quantity::format((float) $record->reorder_level).'. Set a different one for a location, or leave it blank to use the item\'s. 0 = not watched there. The par level is what reorder suggestions top the location up to (blank: twice the reorder level).')
            ->fillForm(fn (ProductVariant $record): array => [
                'location_id' => static::workLocation()->getKey(),
                'reorder_level' => $ownLevel($record, static::workLocation()),
                'par_level' => $parLevel($record, static::workLocation()),
            ])
            ->schema([
                self::locationSelect()
                    ->afterStateUpdated(function (mixed $state, Set $set, ProductVariant $record) use ($ownLevel, $parLevel): void {
                        if (filled($state)) {
                            $set('reorder_level', $ownLevel($record, static::chosenLocation($state)));
                            $set('par_level', $parLevel($record, static::chosenLocation($state)));
                        }
                    }),
                TextInput::make('reorder_level')->label('Reorder at this location')->numeric()->minValue(0)->step(0.001)->placeholder('The item\'s reorder level'),
                TextInput::make('par_level')->label('Par level at this location')->numeric()->minValue(0)->step(0.001)->placeholder('Twice the reorder level'),
            ])
            ->action(function (array $data, ProductVariant $record, Action $action): void {
                self::run($action, function () use ($data, $record): void {
                    $location = static::chosenLocation($data['location_id'] ?? null);
                    $stock = app(StockService::class);

                    DB::transaction(function () use ($stock, $record, $location, $data): void {
                        $stock->setReorderLevel($record, $location, filled($data['reorder_level'] ?? null) ? (float) $data['reorder_level'] : null);
                        $stock->setParLevel($record, $location, filled($data['par_level'] ?? null) ? (float) $data['par_level'] : null);
                    });
                });

                Notification::make()->title('Reorder level saved')->success()->send();
            });
    }

    /**
     * The par level of a one-location shop (retail): what reorder suggestions
     * top the item up to. Its reorder level is on the product form.
     */
    public static function parLevelAction(): Action
    {
        $canManage = fn (): bool => (static::user()?->can('products.manage') ?? false) && ! static::multiLocation();

        return Action::make('parLevel')
            ->label('Par level')
            ->icon(Heroicon::OutlinedBellAlert)
            ->visible($canManage)
            ->authorize($canManage)
            ->modalHeading(fn (ProductVariant $record): string => "Par level: {$record->sku}")
            ->modalDescription(fn (ProductVariant $record): string => 'What reorder suggestions top this item up to once it is at or below its reorder level ('.Quantity::format((float) $record->reorder_level).'). Leave it blank for twice the reorder level.')
            ->fillForm(fn (ProductVariant $record): array => ['par_level' => ($par = static::levelAt($record, static::workLocation())?->par_level) === null ? null : (float) $par])
            ->schema([
                TextInput::make('par_level')->label('Par level')->numeric()->minValue(0)->step(0.001)->placeholder('Twice the reorder level'),
            ])
            ->action(function (array $data, ProductVariant $record, Action $action): void {
                self::run($action, fn () => app(StockService::class)->setParLevel(
                    $record,
                    static::workLocation(),
                    filled($data['par_level'] ?? null) ? (float) $data['par_level'] : null,
                ));

                Notification::make()->title('Par level saved')->success()->send();
            });
    }

    /** Show business-rule failures as a message and keep the form open. */
    private static function run(Action $action, callable $callback): void
    {
        try {
            $callback();
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            $action->halt();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockLevels::route('/'),
        ];
    }
}
