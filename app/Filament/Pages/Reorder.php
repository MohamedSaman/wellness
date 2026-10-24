<?php

namespace App\Filament\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VariantUnit;
use App\Services\ReorderService;
use App\Services\StockService;
use App\Support\CurrentShop;
use App\Support\Quantity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Low stock and reorder (docs/PHARMACY.md §9, branch 10), per location:
 * items at or below their reorder level, with their par level, what is
 * already coming (open orders and requests) and the suggested quantity
 * (ReorderService). From here a location supplied by another (the OPD)
 * builds a stock request up to par, and the location that buys (General
 * Pharmacy) builds draft purchase orders, one per last supplier. Retail
 * shops (one location) get the same list and, with purchasing, the orders.
 */
class Reorder extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $navigationLabel = 'Low stock & reorder';

    protected static ?string $title = 'Low stock and reorder';

    protected static ?int $navigationSort = 5;

    #[Url]
    public ?string $location = null;

    /** @var array<int, array{supplier_id: int, unit_id: int|null, factor: float, unit_cost: int}>|null */
    private ?array $lastPurchases = null;

    public static function canAccess(): bool
    {
        return self::user()?->can('stock.view') ?? false;
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private static function shopHas(string $module): bool
    {
        return app(CurrentShop::class)->getOrFail()->hasModule($module);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if (! ctype_digit((string) $this->location) || ! array_key_exists((int) $this->location, Location::options())) {
            $this->location = (string) (self::user()?->workLocation(app(CurrentShop::class)->getOrFail())->getKey() ?? '');
        }
    }

    public function chosenLocation(): Location
    {
        $shop = app(CurrentShop::class)->getOrFail();

        if (! self::shopHas('multi_location')) {
            return $shop->defaultLocation();
        }

        return Location::query()->find((int) $this->location) ?? $shop->defaultLocation();
    }

    public function filters(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                Select::make('location')
                    ->label('Location')
                    ->options(fn (): array => collect(Location::options())->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])->all())
                    ->selectablePlaceholder(false)
                    ->live()
                    ->afterStateUpdated(fn () => $this->resetTable()),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components(self::shopHas('multi_location')
            ? [EmbeddedSchema::make('filters'), EmbeddedTable::make()]
            : [EmbeddedTable::make()]);
    }

    /** @return array<int, array{supplier_id: int, unit_id: int|null, factor: float, unit_cost: int}> */
    private function lastPurchases(): array
    {
        if ($this->lastPurchases === null) {
            $ids = array_column(app(ReorderService::class)->suggestions($this->chosenLocation()), 'variant_id');
            $this->lastPurchases = $ids === [] ? [] : app(ReorderService::class)->lastPurchases($ids);
        }

        return $this->lastPurchases;
    }

    public function table(Table $table): Table
    {
        $quantity = fn ($state): string => $state === null ? '-' : Quantity::format((float) $state);
        $purchasing = fn (): bool => self::shopHas('purchasing');
        $supplierNames = fn (): array => Supplier::query()->whereKey(array_column($this->lastPurchases(), 'supplier_id'))->pluck('name', 'id')->all();

        return $table
            ->records(fn (): array => collect(app(ReorderService::class)->suggestions($this->chosenLocation()))->keyBy('variant_id')->all())
            ->columns([
                TextColumn::make('product')
                    ->label('Item')
                    ->description(fn (array $record): string => trim($record['sku'].' '.($record['variant'] ?? '')))
                    ->wrap(),
                TextColumn::make('bin_location')->label('Shelf / bin')->placeholder('-')->toggleable(),
                TextColumn::make('on_hand')
                    ->label('On hand')
                    ->formatStateUsing($quantity)
                    ->color(fn ($state): ?string => (float) $state < 0 ? 'danger' : null)
                    ->alignEnd(),
                TextColumn::make('expired')
                    ->label('Expired')
                    ->tooltip('On hand but expired: not counted towards the par level')
                    ->formatStateUsing(fn ($state): string => (float) $state > 0 ? Quantity::format((float) $state) : '-')
                    ->color(fn ($state): ?string => (float) $state > 0 ? 'danger' : null)
                    ->alignEnd()
                    ->visible(fn (): bool => self::shopHas('pharmacy')),
                TextColumn::make('reorder_level')->label('Reorder at')->formatStateUsing($quantity)->alignEnd(),
                TextColumn::make('par_level')->label('Par')->formatStateUsing($quantity)->placeholder('-')->alignEnd()
                    ->tooltip('What suggestions top up to; blank: twice the reorder level'),
                TextColumn::make('incoming')
                    ->label('Coming')
                    ->tooltip('Still to arrive: open purchase orders here, and stock requests from here')
                    ->formatStateUsing($quantity)
                    ->alignEnd(),
                TextColumn::make('suggested')
                    ->label('Suggested')
                    ->formatStateUsing(fn ($state, array $record): string => Quantity::format((float) $state).' '.$record['unit'])
                    ->weight('bold')
                    ->alignEnd(),
                TextColumn::make('supplier')
                    ->label('Last supplier')
                    ->state(fn (array $record): ?string => ($last = $this->lastPurchases()[$record['variant_id']] ?? null) === null ? null : ($supplierNames()[$last['supplier_id']] ?? null))
                    ->placeholder('None on record')
                    ->visible($purchasing),
                TextColumn::make('pack')
                    ->label('Order as')
                    ->state(function (array $record): ?string {
                        $last = $this->lastPurchases()[$record['variant_id']] ?? null;

                        if ($last === null || $record['suggested'] <= 0) {
                            return null;
                        }

                        $packs = (float) ceil(Quantity::normalize($record['suggested'] / $last['factor']));
                        $unit = $last['unit_id'] === null ? $record['unit'] : (string) VariantUnit::query()->whereKey($last['unit_id'])->value('name');

                        return Quantity::format($packs).' '.$unit;
                    })
                    ->placeholder('-')
                    ->visible($purchasing),
            ])
            ->recordActions([$this->levelsAction()])
            ->headerActions([$this->suggestRequestAction(), $this->suggestOrdersAction()])
            ->paginated(false)
            ->emptyStateHeading('Nothing is running low here')
            ->emptyStateDescription('Items show here once they are at or below their reorder level at this location.');
    }

    /** The location's reorder level (with several locations; else the product form's) and par level. */
    private function levelsAction(): Action
    {
        $canManage = fn (): bool => self::user()?->can('products.manage') ?? false;

        return Action::make('levels')
            ->label('Levels')
            ->icon(Heroicon::OutlinedBellAlert)
            ->visible($canManage)
            ->authorize($canManage)
            ->modalHeading(fn (array $record): string => "Reorder and par levels: {$record['sku']}")
            ->fillForm(fn (array $record): array => ['reorder_level' => $record['reorder_level'], 'par_level' => $record['par_level']])
            ->schema([
                TextInput::make('reorder_level')
                    ->label('Reorder at '.$this->chosenLocation()->name)
                    ->numeric()->minValue(0)->step(0.001)
                    ->visible(fn (): bool => self::shopHas('multi_location')),
                TextInput::make('par_level')->label('Par level')->numeric()->minValue(0)->step(0.001)->placeholder('Twice the reorder level'),
            ])
            ->action(function (array $data, array $record, Action $action): void {
                $variant = ProductVariant::query()->findOrFail((int) $record['variant_id']);
                $location = $this->chosenLocation();
                $stock = app(StockService::class);

                try {
                    DB::transaction(function () use ($stock, $variant, $location, $data): void {
                        if (self::shopHas('multi_location') && array_key_exists('reorder_level', $data)) {
                            $stock->setReorderLevel($variant, $location, filled($data['reorder_level']) ? (float) $data['reorder_level'] : null);
                        }

                        $stock->setParLevel($variant, $location, filled($data['par_level'] ?? null) ? (float) $data['par_level'] : null);
                    });
                } catch (PosException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Levels saved')->success()->send();
            });
    }

    /** A stock request up to par, for a location supplied by another (the OPD). */
    private function suggestRequestAction(): Action
    {
        $visible = function (): bool {
            $user = self::user();

            return self::shopHas('multi_location')
                && $user !== null
                && $user->can('stock.request')
                && $user->mayUseLocation($this->chosenLocation())
                && ! $this->chosenLocation()->is_default;
        };

        return Action::make('suggestRequest')
            ->label('Suggest request')
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->visible($visible)
            ->authorize($visible)
            ->url(fn (): string => StockRequestResource::getUrl('create', ['suggest' => $this->chosenLocation()->getKey()]));
    }

    /** Draft purchase orders, one per last supplier, for the location that buys. */
    private function suggestOrdersAction(): Action
    {
        $visible = fn (): bool => self::shopHas('purchasing') && (self::user()?->can('purchasing.manage') ?? false);

        return Action::make('suggestOrders')
            ->label('Suggest purchase orders')
            ->icon(Heroicon::OutlinedShoppingCart)
            ->visible($visible)
            ->authorize($visible)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Draft purchase orders for '.$this->chosenLocation()->name)
            ->modalDescription('One draft order per supplier: each item goes to the supplier it was last received from, in the pack it came in, rounded up to whole packs, at that pack\'s last cost. Check and send them from Purchase orders. Items never received from a supplier are left out.')
            ->modalSubmitActionLabel('Make drafts')
            ->action(function (Action $action): void {
                try {
                    $result = app(ReorderService::class)->purchaseOrders($this->chosenLocation(), self::user() ?? abort(403));
                } catch (PosException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                $skipped = array_map(fn (array $row): string => "{$row['sku']} ({$row['reason']})", $result['skipped']);

                $notification = Notification::make()
                    ->title($result['orders'] === [] ? 'No order made' : 'Draft orders: '.implode(', ', array_map(fn (PurchaseOrder $order): string => $order->po_number, $result['orders'])))
                    ->body($skipped === [] ? null : 'Left out: '.implode(', ', $skipped).'.')
                    ->status($result['orders'] === [] ? 'warning' : 'success');

                // What was left out stays on screen until it is read.
                ($skipped === [] ? $notification : $notification->persistent())->send();

                if ($result['orders'] !== []) {
                    $action->redirect(PurchaseOrderResource::getUrl('index'));
                }
            });
    }
}
