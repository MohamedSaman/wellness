<?php

namespace App\Filament\Resources\StockCounts\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\StockCounts\StockCountResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Support\ShopMoney;
use App\Models\ProductVariant;
use App\Models\StockBatch;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\User;
use App\Services\Data\BatchInput;
use App\Services\StockCountService;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Counting: the sheet's lines, each counted on screen in base units, or in
 * a pack (Count in packs); lines can be added for an item or a batch found
 * on the shelf. A blind count hides what the system holds here. The owner
 * reviews and posts (Review and post). Once posted, each line shows what
 * the system held at posting and the variance written.
 */
class CountStockCount extends ManageRelatedRecords
{
    protected static string $resource = StockCountResource::class;

    protected static string $relationship = 'lines';

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return $record instanceof StockCount && (self::user()?->can('view', $record) ?? false);
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public function stockCount(): StockCount
    {
        /** @var StockCount $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getTitle(): string|Htmlable
    {
        $count = $this->stockCount();

        return "Stock count {$count->count_number}";
    }

    public function getSubheading(): ?string
    {
        $count = $this->stockCount()->loadMissing('location');

        return sprintf(
            '%s · %s · %s%s. Counts never stop sales: each line is measured from what the system held when it was counted, so what sells or moves before posting is not a variance.',
            $count->location->name,
            $count->coverage(),
            $count->status->label(),
            $count->blind ? ' · blind' : '',
        );
    }

    private function editable(): bool
    {
        return $this->stockCount()->isInProgress() && (self::user()?->can('stock.count') ?? false);
    }

    private function hidesSystem(): bool
    {
        return $this->stockCount()->blind && $this->stockCount()->isInProgress();
    }

    public function table(Table $table): Table
    {
        $quantity = fn ($state): string => $state === null ? '-' : Quantity::format((float) $state);
        $posted = fn (): bool => $this->stockCount()->posted_at !== null;

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['variant.product', 'variant.units', 'countedUnit']))
            ->columns([
                TextColumn::make('variant.product.name')
                    ->label('Item')
                    ->description(fn (StockCountLine $record): string => trim($record->variant->sku.($record->variant->bin_location ? ' · '.$record->variant->bin_location : '')))
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('variant', fn (Builder $v) => $v
                        ->where('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', $search)
                        ->orWhere('bin_location', 'like', "{$search}%")
                        ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', "%{$search}%")))),
                TextColumn::make('batch')
                    ->label('Batch')
                    ->state(fn (StockCountLine $record): ?string => $record->batch_no === null && $record->stock_batch_id !== null ? 'Unallocated' : $record->batchLabel())
                    ->description(fn (StockCountLine $record): ?string => $record->stock_batch_id === null && $record->batch_no !== null ? 'Found on the shelf' : null)
                    ->placeholder('-'),
                TextColumn::make('expected_quantity')
                    ->label('On the sheet')
                    ->tooltip('On hand when the count started')
                    ->formatStateUsing($quantity)
                    ->alignEnd()
                    ->hidden(fn (): bool => $this->hidesSystem()),
                TextInputColumn::make('counted_quantity')
                    ->label('Counted')
                    ->tooltip('In base units; use Count in packs for boxes')
                    ->type('number')
                    ->step(0.001)
                    ->rules(['nullable', 'numeric', 'min:0'])
                    ->disabled(fn (): bool => ! $this->editable())
                    ->updateStateUsing(function (mixed $state, StockCountLine $record): mixed {
                        try {
                            $line = app(StockCountService::class)->enter($record, $state === null || $state === '' ? null : (float) $state, null, self::user() ?? abort(403));
                        } catch (PosException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            return $record->counted_quantity;
                        }

                        return $line->counted_quantity;
                    }),
                TextColumn::make('counted_system_quantity')
                    ->label('System when counted')
                    ->tooltip('Taken when the line was counted (again when counted again): the variance is measured from it')
                    ->formatStateUsing($quantity)
                    ->placeholder('-')
                    ->alignEnd()
                    ->hidden(fn (): bool => $this->hidesSystem()),
                TextColumn::make('unit')
                    ->label('')
                    ->state(fn (StockCountLine $record): string => (string) $record->variant->product->unit),
                TextColumn::make('counted_as')
                    ->label('Counted as')
                    ->state(fn (StockCountLine $record): ?string => $record->countedUnit === null || $record->counted_unit_quantity === null ? null
                        : Quantity::format((float) $record->counted_unit_quantity).' '.$record->countedUnit->name)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('system_quantity')->label('System at posting')->tooltip('What the line held when the count was posted, before its variance')->formatStateUsing($quantity)->alignEnd()->visible($posted),
                TextColumn::make('variance')
                    ->label('Variance')
                    ->formatStateUsing(fn ($state): string => $state === null ? '-' : ((float) $state > 0 ? '+' : '').Quantity::format((float) $state))
                    ->color(fn ($state): ?string => $state === null || (float) $state == 0.0 ? null : ((float) $state < 0 ? 'danger' : 'success'))
                    ->alignEnd()
                    ->visible($posted),
                TextColumn::make('variance_value')
                    ->label('Value')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : ShopMoney::format($state))
                    ->placeholder('-')
                    ->alignEnd()
                    ->visible(fn (): bool => $posted() && (self::user()?->can('cost.view') ?? false)),
            ])
            ->filters([
                TernaryFilter::make('counted')
                    ->label('Counted')
                    ->trueLabel('Counted')
                    ->falseLabel('Not counted yet')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('counted_quantity'),
                        false: fn (Builder $query): Builder => $query->whereNull('counted_quantity'),
                    ),
            ])
            ->defaultSort('sort_order')
            ->paginated([50, 100, 'all'])
            ->recordActions([$this->countInPacksAction()])
            ->headerActions([$this->addLineAction()]);
    }

    /** Count a line in a pack (boxes, strips) instead of base units. */
    private function countInPacksAction(): Action
    {
        return Action::make('countInPacks')
            ->label('Count in packs')
            ->icon(Heroicon::OutlinedCube)
            ->visible(fn (StockCountLine $record): bool => $this->editable() && $record->variant->units->where('is_active', true)->count() > 1)
            ->modalHeading(fn (StockCountLine $record): string => 'Count '.$record->variant->product->name.($record->batchLabel() === null ? '' : ' · '.$record->batchLabel()))
            ->modalWidth('md')
            ->fillForm(fn (StockCountLine $record): array => ['unit_id' => $record->counted_unit_id, 'quantity' => $record->counted_unit_quantity === null ? null : (float) $record->counted_unit_quantity])
            ->schema([
                Select::make('unit_id')
                    ->label('Unit')
                    ->options(fn (StockCountLine $record): array => StockTransferResource::unitOptions($record->product_variant_id))
                    ->placeholder('Base unit'),
                TextInput::make('quantity')->label('Counted')->numeric()->required()->minValue(0)->step(0.001),
            ])
            ->action(function (array $data, StockCountLine $record, Action $action): void {
                try {
                    app(StockCountService::class)->enter($record, (float) $data['quantity'], filled($data['unit_id'] ?? null) ? (int) $data['unit_id'] : null, self::user() ?? abort(403));
                } catch (PosException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();
                }
            });
    }

    /** Add an item, or a batch (on record, or found on the shelf), that is not on the sheet. */
    private function addLineAction(): Action
    {
        return Action::make('addLine')
            ->label('Add a line')
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn (): bool => $this->editable())
            ->modalHeading('Add something not on the sheet')
            ->schema([
                Select::make('variant_id')
                    ->label('Item')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => StockTransferResource::searchItems($search))
                    ->getOptionLabelUsing(fn ($value): ?string => StockTransferResource::itemLabel(ProductVariant::query()->with('product')->find((int) $value)))
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('batch_id', null);
                        $set('unit_id', null);
                    }),
                Select::make('batch_id')
                    ->label('Batch')
                    ->options(fn (Get $get): array => filled($get('variant_id')) ? StockBatch::query()
                        ->where('product_variant_id', (int) $get('variant_id'))
                        ->where('location_id', $this->stockCount()->location_id)
                        ->where('is_unallocated', false)
                        ->fefo()
                        ->get()
                        ->mapWithKeys(fn (StockBatch $batch): array => [(string) $batch->getKey() => $batch->label().' — '.Quantity::format((float) $batch->quantity).' on record'])
                        ->all() + ['new' => 'A batch not on record (found on the shelf)'] : [])
                    ->required(fn (Get $get): bool => StockTransferResource::tracksBatches($get('variant_id')))
                    ->visible(fn (Get $get): bool => StockTransferResource::tracksBatches($get('variant_id')))
                    ->live(),
                TextInput::make('batch_no')->label('Batch no.')->maxLength(50)
                    ->required(fn (Get $get): bool => $get('batch_id') === 'new')
                    ->visible(fn (Get $get): bool => $get('batch_id') === 'new'),
                TextInput::make('expiry')->label('Expiry')->maxLength(10)->helperText('As printed: 03/2027 or 15/03/2027.')
                    ->required(fn (Get $get): bool => $get('batch_id') === 'new')
                    ->visible(fn (Get $get): bool => $get('batch_id') === 'new'),
                Select::make('unit_id')
                    ->label('Unit')
                    ->options(fn (Get $get): array => StockTransferResource::unitOptions($get('variant_id')))
                    ->placeholder('Base unit'),
                TextInput::make('quantity')->label('Counted')->numeric()->minValue(0)->step(0.001)->helperText('Leave blank to count it later.'),
            ])
            ->action(function (array $data, Action $action): void {
                $batch = $data['batch_id'] ?? null;

                try {
                    app(StockCountService::class)->addLine(
                        $this->stockCount(),
                        (int) $data['variant_id'],
                        self::user() ?? abort(403),
                        filled($batch) && $batch !== 'new' ? (int) $batch : null,
                        $batch === 'new' ? new BatchInput((string) $data['batch_no'], (string) $data['expiry']) : null,
                        filled($data['quantity'] ?? null) ? (float) $data['quantity'] : null,
                        filled($data['unit_id'] ?? null) ? (int) $data['unit_id'] : null,
                    );
                } catch (PosException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Line added')->success()->send();
            });
    }

    protected function getHeaderActions(): array
    {
        $count = $this->stockCount();

        return [
            Action::make('print')
                ->label('Print count sheet')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (): string => route('stock.print.count', ['stockCount' => $count]).'?print=1', shouldOpenInNewTab: true),
            Action::make('review')
                ->label('Review and post')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->visible(fn (): bool => $count->isInProgress() && (self::user()?->can('stock.adjust') ?? false))
                ->url(fn (): string => StockCountResource::getUrl('review', ['record' => $count])),
            Action::make('cancel')
                ->label('Cancel count')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (): bool => $count->isInProgress() && (self::user()?->canAny(['stock.count', 'stock.adjust']) ?? false))
                ->requiresConfirmation()
                ->modalDescription('The count is closed without changing any stock.')
                ->action(function (Action $action) use ($count): void {
                    try {
                        app(StockCountService::class)->cancel($count, self::user() ?? abort(403));
                    } catch (PosException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();
                        $action->halt();
                    }

                    Notification::make()->title('Count cancelled')->success()->send();
                    $action->redirect(StockCountResource::getUrl('count', ['record' => $count]));
                }),
        ];
    }
}
