<?php

namespace App\Filament\Resources\ExpiringStock;

use App\Enums\AdjustmentReason;
use App\Enums\BatchStatus;
use App\Exceptions\PosException;
use App\Filament\Resources\ExpiringStock\Pages\ListExpiringStock;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\StockBatches\StockBatchResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Resources\StockWriteOffs\StockWriteOffResource;
use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use App\Filament\Support\ShopMoney;
use App\Models\Location;
use App\Models\StockBatch;
use App\Models\StockTransfer;
use App\Models\StockWriteOff;
use App\Services\Data\TransferLine;
use App\Services\Data\WriteOffLine;
use App\Services\ExpiryService;
use App\Services\StockTransferService;
use App\Services\StockWriteOffService;
use App\Support\CurrentShop;
use App\Support\Quantity;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Expiry alerts (pharmacy, docs/PHARMACY.md §9, branch 10): the batches
 * holding stock that have expired, or expire within the shop's warning
 * window (near_expiry_warning_days), per location, with days left and their
 * value at batch cost. The unallocated batch is never listed. Selected
 * batches can be written off (owner, stock.write_off), moved to a location
 * that does not sell such as quarantine (a transfer, stock.transfer), or
 * sent back to the supplier (a supplier return, purchasing).
 */
class ExpiringStockResource extends PermissionResource
{
    protected static ?string $model = StockBatch::class;

    protected static ?string $slug = 'expiry';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Expiry';

    protected static ?string $modelLabel = 'batch';

    protected static ?string $pluralModelLabel = 'Expiring and expired stock';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false) && parent::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $expired = app(ExpiryService::class)->query(ExpiryService::EXPIRED)->count();

        return $expired > 0 ? (string) $expired : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Batches past their expiry date with stock';
    }

    public static function table(Table $table): Table
    {
        $expiry = app(ExpiryService::class);
        $multiLocation = fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('multi_location');
        $canSeeCost = fn (): bool => static::user()?->can('cost.view') ?? false;

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['variant.product', 'location']))
            ->columns([
                TextColumn::make('variant.product.name')
                    ->label('Item')
                    ->description(fn (StockBatch $record): string => (string) $record->variant->sku)
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => $q
                        ->where('batch_no', 'like', '%'.strtoupper($search).'%')
                        ->orWhereHas('variant', fn (Builder $v) => $v->where('sku', 'like', "%{$search}%")
                            ->orWhere('barcode', $search)
                            ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', "%{$search}%"))))),
                TextColumn::make('location.name')->label('Location')->visible($multiLocation),
                TextColumn::make('batch_no')->label('Batch'),
                TextColumn::make('expiry_date')->label('Expiry')->date('d/m/Y')->sortable(),
                TextColumn::make('days_left')
                    ->label('Days left')
                    ->state(fn (StockBatch $record): ?int => $expiry->daysLeft($record))
                    ->formatStateUsing(fn (?int $state): string => match (true) {
                        $state === null => '-',
                        $state < 0 => 'Expired '.abs($state).' '.str('day')->plural(abs($state)).' ago',
                        $state === 0 => 'Expires today',
                        default => $state.' '.str('day')->plural($state),
                    })
                    ->color(fn (?int $state): string => $state !== null && $state < 0 ? 'danger' : 'warning')
                    ->alignEnd(),
                TextColumn::make('quantity')
                    ->label('On hand')
                    ->formatStateUsing(fn ($state): string => Quantity::format((float) $state))
                    ->suffix(fn (StockBatch $record): string => ' '.$record->variant->product->unit)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('value')
                    ->label('Value at cost')
                    ->tooltip('On hand x what the batch cost: what writing it off costs')
                    ->state(fn (StockBatch $record): int => $expiry->value($record))
                    ->formatStateUsing(fn (int $state): ?string => ShopMoney::format($state))
                    ->alignEnd()
                    ->visible($canSeeCost),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BatchStatus $state): string => $state->label())
                    ->color(fn (BatchStatus $state): string => $state === BatchStatus::Active ? 'success' : 'danger')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('location')
                    ->label('Location')
                    ->options(fn (): array => Location::options())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('location_id', $data['value'])
                        : $query)
                    ->visible($multiLocation),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->scopes(['fefo']))
            ->recordUrl(fn (StockBatch $record): string => StockBatchResource::historyUrl($record))
            ->toolbarActions([
                BulkActionGroup::make([
                    static::writeOffAction(),
                    static::moveAction(),
                    static::supplierReturnAction(),
                ])->label('With the selected batches'),
            ])
            ->emptyStateHeading('Nothing here')
            ->emptyStateDescription('No batch with stock on this list.');
    }

    /** Write the selected batches off, all they hold, valued at batch cost: one note per location. */
    public static function writeOffAction(): BulkAction
    {
        $canWriteOff = fn (): bool => static::user()?->can('stock.write_off') ?? false;

        return BulkAction::make('writeOff')
            ->label('Write off')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible($canWriteOff)
            ->authorize($canWriteOff)
            ->modalHeading('Write off the selected batches')
            ->modalDescription('All the stock of each batch leaves for good, valued at what the batch cost. One write-off note is made per location, to print and sign.')
            ->modalSubmitActionLabel('Write off')
            ->schema([
                Select::make('reason')->options(AdjustmentReason::writeOffOptions())->default(AdjustmentReason::Expired->value)->required()->native(false)->live(),
                Textarea::make('note')->rows(2)->maxLength(500)
                    ->required(fn ($get): bool => $get('reason') === AdjustmentReason::Other->value)
                    ->placeholder('Destroyed by ..., witnessed by ...'),
            ])
            ->action(function (Collection $records, array $data, BulkAction $action): void {
                try {
                    $notes = app(StockWriteOffService::class)->writeOff(
                        array_values(array_map(fn (int|string $id): WriteOffLine => new WriteOffLine((int) $id), $records->modelKeys())),
                        AdjustmentReason::from((string) $data['reason']),
                        static::user() ?? abort(403),
                        $data['note'] ?? null,
                    );
                } catch (PosException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title('Written off: '.implode(', ', array_map(fn (StockWriteOff $note): string => $note->write_off_number, $notes)))
                    ->success()
                    ->send();

                if (count($notes) === 1) {
                    $action->redirect(StockWriteOffResource::getUrl('view', ['record' => $notes[0]]));
                }
            })
            ->deselectRecordsAfterCompletion();
    }

    /** Move the selected batches, all they hold, to another location (e.g. quarantine, which does not sell). */
    public static function moveAction(): BulkAction
    {
        $canMove = fn (): bool => (static::user()?->can('stock.transfer') ?? false)
            && app(CurrentShop::class)->getOrFail()->hasModule('multi_location');

        return BulkAction::make('move')
            ->label('Move to location')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible($canMove)
            ->authorize($canMove)
            ->modalHeading('Move the selected batches')
            ->modalDescription('A transfer moves all the stock of each batch to the location you choose: a quarantine shelf that does not sell keeps it out of FEFO until it is returned or written off.')
            ->modalSubmitActionLabel('Move')
            ->schema([
                Select::make('to_location_id')
                    ->label('To')
                    ->options(fn (): array => Location::query()->where('is_active', true)->ordered()->get()
                        ->mapWithKeys(fn (Location $location): array => [(int) $location->getKey() => $location->name.($location->is_sellable ? '' : ' (does not sell)')])
                        ->all())
                    ->required()
                    ->native(false),
                Textarea::make('note')->rows(2)->maxLength(500)->default('Expiring stock'),
            ])
            ->action(function (Collection $records, array $data, BulkAction $action): void {
                $user = static::user() ?? abort(403);
                $to = Location::query()->findOrFail((int) $data['to_location_id']);

                // Read again: each batch moves all it holds now.
                $batches = StockBatch::query()->whereKey($records->modelKeys())->where('location_id', '!=', $to->getKey())->orderBy('id')->get();

                try {
                    $transfers = DB::transaction(function () use ($batches, $to, $user, $data): array {
                        $transfers = [];

                        foreach ($batches->groupBy('location_id') as $fromId => $group) {
                            $transfers[] = app(StockTransferService::class)->create(
                                Location::query()->findOrFail((int) $fromId),
                                $to,
                                array_values($group->map(fn (StockBatch $batch): TransferLine => new TransferLine((int) $batch->product_variant_id, (float) $batch->quantity, null, (int) $batch->getKey()))->all()),
                                $user,
                                $data['note'] ?? null,
                            );
                        }

                        return $transfers;
                    });
                } catch (PosException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                if ($transfers === []) {
                    Notification::make()->title("The selected batches are already at {$to->name}.")->warning()->send();

                    return;
                }

                Notification::make()
                    ->title('Moved to '.$to->name.': '.implode(', ', array_map(fn (StockTransfer $transfer): string => $transfer->transfer_number, $transfers)))
                    ->success()
                    ->send();

                if (count($transfers) === 1) {
                    $action->redirect(StockTransferResource::getUrl('view', ['record' => $transfers[0]]));
                }
            })
            ->deselectRecordsAfterCompletion();
    }

    /** Start a supplier return with the selected batches (one location at a time): the return form opens filled in. */
    public static function supplierReturnAction(): BulkAction
    {
        $canReturn = fn (): bool => (static::user()?->can('purchasing.manage') ?? false)
            && app(CurrentShop::class)->getOrFail()->hasModule('purchasing');

        return BulkAction::make('supplierReturn')
            ->label('Return to supplier')
            ->icon(Heroicon::OutlinedTruck)
            ->visible($canReturn)
            ->authorize($canReturn)
            ->action(function (Collection $records, BulkAction $action): void {
                if ($records->pluck('location_id')->unique()->count() > 1) {
                    Notification::make()->title('Choose batches at one location: a supplier return leaves from one location.')->danger()->send();

                    return;
                }

                $action->redirect(SupplierReturnResource::getUrl('create', ['batches' => $records->modelKeys()]));
            });
    }

    /** @return array<string, int> list => batches on it, for the tab badges */
    public static function counts(): array
    {
        $expiry = app(ExpiryService::class);

        return [
            ExpiryService::SOON => $expiry->query(ExpiryService::SOON)->count(),
            ExpiryService::EXPIRED => $expiry->query(ExpiryService::EXPIRED)->count(),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpiringStock::route('/'),
        ];
    }
}
