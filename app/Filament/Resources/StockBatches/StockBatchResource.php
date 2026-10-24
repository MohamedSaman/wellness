<?php

namespace App\Filament\Resources\StockBatches;

use App\Enums\AdjustmentReason;
use App\Enums\BatchStatus;
use App\Exceptions\PosException;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\StockBatches\Pages\ListStockBatches;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Support\LabelActions;
use App\Filament\Support\ShopMoney;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockBatch;
use App\Services\BatchService;
use App\Services\StockService;
use App\Support\CurrentShop;
use App\Support\Quantity;
use App\Support\UnitCost;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Batches and expiry (pharmacy, docs/PHARMACY.md §4.5): every batch of every
 * item at every location, soonest expiry first, with what is on hand. The
 * expiry filters never show the unallocated batch (stock sold beyond the
 * recorded batches); it has its own filter, and a Settle action that
 * attributes it to the batch the units came from. Batches change only
 * through stock movements; Count records a stock count of one batch.
 * With the labels module, selected batches get labels with their batch.
 */
class StockBatchResource extends PermissionResource
{
    protected static ?string $model = StockBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Batches';

    protected static ?string $modelLabel = 'batch';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false) && parent::canAccess();
    }

    public static function table(Table $table): Table
    {
        $quantity = fn ($state): string => Quantity::format((float) $state);
        $multiLocation = fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('multi_location');
        $today = fn (): string => app(BatchService::class)->today()->toDateString();
        $warnBy = fn (): string => app(BatchService::class)->today()
            ->addDays(app(BatchService::class)->nearExpiryWarningDays())->toDateString();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['variant.product', 'location']))
            ->columns([
                TextColumn::make('variant.product.name')
                    ->label('Item')
                    ->description(fn (StockBatch $record): string => (string) $record->variant->sku)
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => $q
                        ->where('batch_no', 'like', '%'.BatchService::normalizeBatchNo($search).'%')
                        ->orWhereHas('variant', fn (Builder $v) => $v->where('sku', 'like', "%{$search}%")
                            ->orWhere('barcode', $search)
                            ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', "%{$search}%"))))),
                TextColumn::make('location.name')->label('Location')->visible($multiLocation),
                TextColumn::make('batch_no')
                    ->label('Batch')
                    ->state(fn (StockBatch $record): string => $record->is_unallocated ? 'Unallocated' : $record->batch_no)
                    ->badge(fn (StockBatch $record): bool => $record->is_unallocated)
                    ->color(fn (StockBatch $record): ?string => $record->is_unallocated ? 'danger' : null)
                    ->tooltip(fn (StockBatch $record): ?string => $record->is_unallocated
                        ? 'Sold beyond the recorded batches (offline, or negative stock allowed). The next delivery settles it.'
                        : null),
                TextColumn::make('expiry_date')
                    ->label('Expiry')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->sortable()
                    ->color(fn (StockBatch $record): ?string => match (true) {
                        $record->expiry_date === null => null,
                        $record->expiry_date->toDateString() < $today() => 'danger',
                        $record->expiry_date->toDateString() <= $warnBy() => 'warning',
                        default => null,
                    })
                    ->description(fn (StockBatch $record): ?string => $record->expiry_date !== null && $record->expiry_date->toDateString() < $today() ? 'Expired' : null),
                TextColumn::make('quantity')
                    ->label('On hand')
                    ->formatStateUsing($quantity)
                    ->suffix(fn (StockBatch $record): string => ' '.$record->variant->product->unit)
                    ->color(fn (StockBatch $record): ?string => (float) $record->quantity < 0 ? 'danger' : null)
                    ->weight(fn (StockBatch $record): ?string => (float) $record->quantity < 0 ? 'bold' : null)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BatchStatus $state): string => $state->label())
                    ->color(fn (BatchStatus $state): string => $state === BatchStatus::Active ? 'success' : 'danger')
                    ->toggleable(),
                TextColumn::make('unit_cost')
                    ->label('Batch cost')
                    ->tooltip('Purchase cost per base unit when the batch first arrived')
                    ->formatStateUsing(fn ($state): ?string => ShopMoney::format(UnitCost::toMinor((string) $state)))
                    ->visible(fn (): bool => static::user()?->can('cost.view') ?? false)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('received_at')->label('Received')->date('d/m/Y')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('variant')
                    ->label('Item')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => ProductVariant::query()
                        ->whereHas('product', fn (Builder $p) => $p->where('track_batches', true))
                        ->where(fn (Builder $q) => $q->where('sku', 'like', "%{$search}%")->orWhere('barcode', $search))
                        ->limit(50)
                        ->pluck('sku', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ProductVariant::query()->whereKey($value)->value('sku'))
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('product_variant_id', $data['value'])
                        : $query),
                SelectFilter::make('location')
                    ->label('Location')
                    ->options(fn (): array => Location::options())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('location_id', $data['value'])
                        : $query)
                    ->visible($multiLocation),
                SelectFilter::make('expiry')
                    ->label('Expiry')
                    ->options([
                        'expired' => 'Expired',
                        '30' => 'Expiring within 30 days',
                        '60' => 'Expiring within 60 days',
                        '90' => 'Expiring within 90 days',
                        '180' => 'Expiring within 180 days',
                    ])
                    // Expiry lists never include the unallocated batch (decided, PHARMACY.md §6.4).
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! filled($value)) {
                            return $query;
                        }

                        $today = app(BatchService::class)->today();

                        return $query->scopes(['expiringBy' => [$value === 'expired' ? $today->subDay() : $today->addDays((int) $value)]]);
                    }),
                Filter::make('in_stock')
                    ->label('With stock only')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->where('quantity', '!=', 0)),
                Filter::make('unallocated')
                    ->label('Unallocated stock only')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('is_unallocated', true)),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('is_unallocated', 'desc')->scopes(['fefo']))
            ->recordUrl(fn (StockBatch $record): string => static::historyUrl($record))
            ->recordActions([
                static::settleAction(),
                ActionGroup::make([
                    static::countAction(),
                    static::correctAction(),
                    Action::make('history')
                        ->label('History')
                        ->icon(Heroicon::OutlinedClock)
                        ->url(fn (StockBatch $record): string => static::historyUrl($record)),
                ])->label('More actions')->tooltip('More actions'),
            ])
            // Labels with the batch and expiry for the selected batches (labels module).
            ->toolbarActions([LabelActions::batchesBulkAction()]);
    }

    public static function historyUrl(StockBatch $record): string
    {
        return StockMovementResource::getUrl('index', [
            'filters' => ['batch' => ['value' => $record->getKey()]],
        ]);
    }

    /** Count one batch (stock take, expiry write-off: count it to 0 with reason Expired). */
    public static function countAction(): Action
    {
        $canAdjust = fn (): bool => static::user()?->can('stock.adjust') ?? false;

        return Action::make('count')
            ->label('Count')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->visible($canAdjust)
            ->authorize($canAdjust)
            ->modalHeading(fn (StockBatch $record): string => "Count batch {$record->label()}")
            ->modalDescription('Enter what you counted of this batch here. The difference is recorded as an adjustment. To write off expired stock, count it to 0 with reason Expired.')
            ->fillForm(fn (StockBatch $record): array => ['counted_quantity' => (float) $record->quantity])
            ->schema([
                TextInput::make('counted_quantity')->label('Counted quantity')->numeric()->required()->minValue(0)->step(0.001),
                Select::make('reason')->options(AdjustmentReason::options())->required()->native(false)->live(),
                Textarea::make('note')->rows(2)->maxLength(500)
                    ->required(fn ($get): bool => $get('reason') === AdjustmentReason::Other->value),
            ])
            ->action(function (array $data, StockBatch $record, Action $action): void {
                self::run($action, fn () => app(StockService::class)->adjustBatchTo(
                    $record->variant,
                    $record->location,
                    $record,
                    (float) $data['counted_quantity'],
                    AdjustmentReason::from($data['reason']),
                    static::user(),
                    $data['note'] ?? null,
                ));

                Notification::make()->title('Count recorded')->success()->send();
            });
    }

    /** Attribute unallocated (oversold) stock to the batch the units really came from. */
    /**
     * Correct a typo in a batch's number or expiry (BatchService::correct()):
     * owner only, with a reason, audited. Past sales keep what they printed.
     */
    public static function correctAction(): Action
    {
        $canCorrect = fn (StockBatch $record): bool => ! $record->is_unallocated && (static::user()?->can('batches.manage') ?? false);

        return Action::make('correct')
            ->label('Correct batch')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible($canCorrect)
            ->authorize($canCorrect)
            ->modalHeading(fn (StockBatch $record): string => "Correct batch {$record->label()}")
            ->modalDescription('Fix a batch number or expiry typed wrongly when the stock was received. The same batch at every location is corrected together. Receipts and past sales keep what they printed. The change and its reason go to the audit log.')
            ->fillForm(fn (StockBatch $record): array => [
                'batch_no' => $record->batch_no,
                'expiry' => $record->expiry_date?->format('d/m/Y'),
            ])
            ->schema([
                TextInput::make('batch_no')->label('Batch no.')->required()->maxLength(50),
                TextInput::make('expiry')
                    ->label('Expiry')
                    ->required()
                    ->maxLength(10)
                    ->helperText('The month or date printed on the pack: 03/2027 means 31/03/2027.'),
                Textarea::make('reason')->required()->rows(2)->maxLength(500)->placeholder('Typo at receiving: the pack says ...'),
            ])
            ->action(function (array $data, StockBatch $record, Action $action): void {
                self::run($action, fn () => app(BatchService::class)->correct(
                    $record,
                    (string) $data['batch_no'],
                    (string) $data['expiry'],
                    (string) $data['reason'],
                    static::user() ?? abort(403),
                ));

                Notification::make()->title('Batch corrected')->success()->send();
            });
    }

    public static function settleAction(): Action
    {
        $canSettle = fn (StockBatch $record): bool => $record->is_unallocated
            && (float) $record->quantity < 0
            && (static::user()?->can('batches.manage') ?? false);

        return Action::make('settle')
            ->label('Settle')
            ->icon(Heroicon::OutlinedArrowsPointingIn)
            ->color('warning')
            ->visible($canSettle)
            ->authorize($canSettle)
            ->modalHeading('Settle unallocated stock')
            ->modalDescription(fn (StockBatch $record): string => Quantity::format(abs((float) $record->quantity)).' '.$record->variant->product->unit
                .' were sold beyond the recorded batches. Choose the batch they came from: its quantity goes down by as much, and the unallocated stock goes back towards zero.')
            ->fillForm(fn (StockBatch $record): array => ['quantity' => abs((float) $record->quantity)])
            ->schema([
                Select::make('batch_id')
                    ->label('From batch')
                    ->options(fn (StockBatch $record): array => StockBatch::query()
                        ->where('product_variant_id', $record->product_variant_id)
                        ->where('location_id', $record->location_id)
                        ->where('is_unallocated', false)
                        ->where('quantity', '>', 0)
                        ->fefo()
                        ->get()
                        ->mapWithKeys(fn (StockBatch $batch): array => [(int) $batch->getKey() => $batch->label().' — '.Quantity::format((float) $batch->quantity).' on hand'])
                        ->all())
                    ->required()
                    ->native(false),
                TextInput::make('quantity')->numeric()->required()->minValue(0.001)->step(0.001),
            ])
            ->action(function (array $data, StockBatch $record, Action $action): void {
                self::run($action, function () use ($data, $record): void {
                    $from = StockBatch::query()
                        ->where('product_variant_id', $record->product_variant_id)
                        ->where('location_id', $record->location_id)
                        ->whereKey((int) $data['batch_id'])
                        ->first() ?? throw new PosException('Choose a batch of this item at this location.');

                    app(StockService::class)->settleUnallocated($from, (float) $data['quantity'], static::user() ?? abort(403));
                });

                Notification::make()->title('Unallocated stock settled')->success()->send();
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
            'index' => ListStockBatches::route('/'),
        ];
    }
}
