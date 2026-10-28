<?php

namespace App\Filament\Resources\SupplierPayables;

use App\Enums\PayableStatus;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\SupplierPayables\Pages\ListSupplierPayables;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\PayablesForm;
use App\Filament\Support\ShopMoney;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\SupplierPayable;
use App\Services\SupplierPayablesService;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Payables (purchasing module): every invoice owed to a supplier (posted
 * GRNs and opening entries), when it is due, how late it is and what is
 * still owed, with aging totals over the filters. Read with payables.view
 * or purchasing.manage; paying (Record payment) and moving a due date need
 * purchasing.manage, enforced in SupplierPayablesService.
 */
class SupplierPayableResource extends PermissionResource
{
    protected static ?string $model = SupplierPayable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Payables';

    protected static ?string $modelLabel = 'invoice owed';

    protected static ?string $pluralModelLabel = 'payables';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('purchasing') ?? false) && parent::canAccess();
    }

    public static function table(Table $table): Table
    {
        // One action on a row: a calendar icon (its name as the tooltip), so the column stays narrow.
        return static::payablesTable($table, withSupplier: true)
            ->recordActions([static::dueDateAction()->iconButton()->tooltip('Change due date')]);
    }

    /** The payables table, on this screen (every supplier) and on a supplier's page. */
    public static function payablesTable(Table $table, bool $withSupplier): Table
    {
        $payables = app(SupplierPayablesService::class);
        $today = $payables->today();
        $late = fn (SupplierPayable $record): int => $record->status->isOpen() ? $record->daysOverdue($today) : 0;
        $money = fn (?int $state): ?string => ShopMoney::format($state);

        // Fits a 1280 px window beside the sidebar: Supplier wraps (a document number never
        // breaks), money is right-aligned, and Invoice date and Paid (Amount less
        // Outstanding) start hidden; so does Status with every supplier listed, which
        // the Supplier column needs the room for (Amount against Outstanding shows it).
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['supplier', 'goodsReceipt']))
            ->columns([
                TextColumn::make('supplier.name')->label('Supplier')->searchable()->sortable()->wrap()->visible($withSupplier),
                TextColumn::make('document')
                    ->label('Document')
                    ->state(fn (SupplierPayable $record): string => $record->documentLabel())
                    ->weight('bold')
                    ->description(fn (SupplierPayable $record): ?string => $record->invoice_no === null ? null : 'Invoice '.$record->invoice_no),
                TextColumn::make('invoice_no')->label('Invoice')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('invoice_date')->label('Invoice date')->date()->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('due_on')
                    ->label('Due')
                    ->date()
                    ->sortable()
                    ->color(fn (SupplierPayable $record): ?string => $late($record) > 0 ? 'danger' : null)
                    ->weight(fn (SupplierPayable $record): ?string => $late($record) > 0 ? 'bold' : null),
                TextColumn::make('days_overdue')
                    ->label('Overdue')
                    ->state(fn (SupplierPayable $record): ?int => $late($record) ?: null)
                    ->formatStateUsing(fn (int $state): string => $state.' '.str('day')->plural($state))
                    ->badge()
                    ->color('danger')
                    ->placeholder('-'),
                TextColumn::make('amount')->formatStateUsing($money)->alignEnd(),
                TextColumn::make('allocated')->label('Paid')->formatStateUsing($money)->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('outstanding')
                    ->label('Outstanding')
                    ->state(fn (SupplierPayable $record): int => $record->outstanding())
                    ->formatStateUsing($money)
                    ->weight('bold')
                    ->alignEnd()
                    ->summarize(Summarizer::make()
                        ->label('Outstanding')
                        ->using(fn (QueryBuilder $query): int => (int) $query->sum(DB::raw("CASE WHEN status = 'reversed' THEN 0 ELSE amount - allocated END")))
                        ->formatStateUsing(fn ($state): ?string => ShopMoney::format((int) $state))),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PayableStatus $state): string => $state->label())
                    ->color(fn (PayableStatus $state): string => $state->color())
                    ->toggleable(isToggledHiddenByDefault: $withSupplier),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible($withSupplier),
                SelectFilter::make('status')
                    ->multiple()
                    ->options(PayableStatus::options())
                    ->default(PayableStatus::openValues()),
                SelectFilter::make('aging')
                    ->label('Aging')
                    ->options(SupplierPayablesService::AGING_BUCKETS)
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $payables->whereBucket($query->whereIn('status', PayableStatus::openValues()), (string) $data['value'])),
                Filter::make('overdue')
                    ->label('Overdue')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('status', PayableStatus::openValues())
                        ->where('due_on', '<', $today->toDateString()))
                    ->toggle(),
                Filter::make('due_this_week')
                    ->label('Due this week')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('status', PayableStatus::openValues())
                        ->where('due_on', '>=', $today->toDateString())
                        ->where('due_on', '<', $today->addDays(7)->toDateString()))
                    ->toggle(),
            ])
            ->defaultSort('due_on')
            ->recordUrl(fn (SupplierPayable $record): ?string => static::documentUrl($record))
            ->emptyStateHeading('Nothing owed')
            ->emptyStateDescription('Posted goods received notes and opening balances show here until they are paid.');
    }

    /** The GRN of an invoice (for those who can open it), else the supplier's page. */
    public static function documentUrl(SupplierPayable $record): ?string
    {
        $user = static::user();

        if ($record->goods_receipt_id !== null && $record->goodsReceipt instanceof GoodsReceipt && ($user?->can('view', $record->goodsReceipt) ?? false)) {
            return GoodsReceiptResource::getUrl('view', ['record' => $record->goodsReceipt]);
        }

        return ($user?->can('view', $record->supplier) ?? false) ? SupplierResource::getUrl('view', ['record' => $record->supplier]) : null;
    }

    public static function dueDateAction(): Action
    {
        return PayablesForm::dueDateAction(fn ($record): ?SupplierPayable => $record instanceof SupplierPayable ? $record : null);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierPayables::route('/'),
        ];
    }
}
