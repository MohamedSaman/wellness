<?php

namespace App\Filament\Resources\Sales;

use App\Enums\BillType;
use App\Enums\DrugSchedule;
use App\Enums\SaleStatus;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Support\ShopMoney;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemBatch;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Support\CurrentShop;
use App\Support\Quantity;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only: sales are never edited or deleted. The only changes are the
 * void and return actions on the detail page, which go through the services.
 */
class SaleResource extends PermissionResource
{
    protected static ?string $model = Sale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'invoice_no';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = static::user();

        // "sales.view" alone means the user's own sales only.
        if ($user !== null && ! $user->can('sales.view_all')) {
            $query->where('user_id', $user->getKey());
        }

        return $query;
    }

    /** How many offline sales wait for the owner's review. */
    public static function getNavigationBadge(): ?string
    {
        $user = static::user();

        if ($user === null || ! $user->can('sales.review')) {
            return null;
        }

        $count = Sale::query()
            ->awaitingReview()
            ->when(! $user->can('sales.view_all'), fn (Builder $query) => $query->where('user_id', $user->getKey()))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Offline sales waiting for review';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('cashier'))
            ->columns([
                TextColumn::make('invoice_no')->label('Invoice')->searchable()->sortable(),
                TextColumn::make('sold_at')->dateTime()->sortable(),
                TextColumn::make('cashier.name')->label('Cashier'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (SaleStatus $state): string => ucwords(str_replace('_', ' ', $state->value)))
                    ->color(fn (SaleStatus $state): string => match ($state) {
                        SaleStatus::Completed => 'success',
                        SaleStatus::PartiallyReturned => 'warning',
                        SaleStatus::Returned, SaleStatus::Voided => 'danger',
                    }),
                TextColumn::make('grand_total')->label('Total')->formatStateUsing(fn ($state): ?string => ShopMoney::format((int) $state))->sortable(),
                TextColumn::make('refunded_total')->label('Refunded')->formatStateUsing(fn ($state): ?string => ShopMoney::format((int) $state)),
                TextColumn::make('review')
                    ->label('Review')
                    ->badge()
                    ->getStateUsing(fn (Sale $record): ?string => match (true) {
                        ! $record->needs_review => null,
                        $record->reviewed_at === null => 'Needs review',
                        default => 'Reviewed',
                    })
                    ->color(fn (?string $state): string => $state === 'Needs review' ? 'warning' : 'gray'),
            ])
            ->filters([
                Filter::make('awaiting_review')
                    ->label('Needs review')
                    ->query(fn (Builder $query) => $query->scopes(['awaitingReview'])),
                // OPD bills charged below the shop's share of their medicines' list value (2026-10-22).
                Filter::make('opd_below_list')
                    ->label('OPD medicines below list')
                    ->query(fn (Builder $query) => $query->where('opd_below_list', true))
                    ->visible(fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('pharmacy')),
                SelectFilter::make('status')->options(collect(SaleStatus::cases())->mapWithKeys(fn (SaleStatus $status) => [$status->value => ucwords(str_replace('_', ' ', $status->value))])->all()),
            ])
            ->defaultSort('sold_at', 'desc')
            ->recordUrl(fn (Sale $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn ($state): ?string => ShopMoney::format((int) $state);

        return $schema->components([
            Section::make()
                ->columns(4)
                ->schema([
                    TextEntry::make('invoice_no')->label('Invoice'),
                    TextEntry::make('status')->badge()->formatStateUsing(fn (SaleStatus $state): string => ucwords(str_replace('_', ' ', $state->value))),
                    TextEntry::make('sold_at')->dateTime(),
                    TextEntry::make('cashier.name')->label('Cashier'),
                    TextEntry::make('subtotal')->formatStateUsing($money),
                    TextEntry::make('discount_total')->label('Discount')->formatStateUsing($money),
                    TextEntry::make('tax_total')->label('Tax')->formatStateUsing($money),
                    TextEntry::make('grand_total')->label('Total')->formatStateUsing($money),
                    TextEntry::make('paid_total')->label('Paid')->formatStateUsing($money),
                    TextEntry::make('change_due')->label('Change')->formatStateUsing($money),
                    TextEntry::make('refunded_total')->label('Refunded')->formatStateUsing($money),
                    TextEntry::make('void_reason')->label('Void reason')->placeholder('-')->visible(fn (Sale $record): bool => $record->status === SaleStatus::Voided),
                    // OPD bill (pharmacy): the consultation plus one Medicines amount.
                    TextEntry::make('bill_type')
                        ->label('Bill')
                        ->badge()
                        ->color('info')
                        ->formatStateUsing(fn (BillType $state): string => $state->label())
                        ->visible(fn (Sale $record): bool => $record->bill_type === BillType::Opd),
                    TextEntry::make('opd_medicines_amount')
                        ->label('Medicines amount')
                        ->formatStateUsing($money)
                        ->placeholder('-')
                        ->visible(fn (Sale $record): bool => $record->bill_type === BillType::Opd),
                    // The medicines at list price, never a cost (2026-10-22); flagged when charged below the shop's share of it.
                    TextEntry::make('opd_medicines_list_value')
                        ->label('Medicines at list price')
                        ->formatStateUsing($money)
                        ->placeholder('-')
                        ->badge(fn (Sale $record): bool => $record->opd_below_list)
                        ->color(fn (Sale $record): ?string => $record->opd_below_list ? 'warning' : null)
                        ->suffix(fn (Sale $record): string => $record->opd_below_list ? ' · charged below list' : '')
                        ->visible(fn (Sale $record): bool => $record->bill_type === BillType::Opd && $record->opd_medicines_list_value !== null),
                    TextEntry::make('customer_name')
                        ->label(fn (): string => app(CurrentShop::class)->getOrFail()->hasModule('pharmacy') ? 'Patient' : 'Customer')
                        ->visible(fn (Sale $record): bool => filled($record->customer_name)),
                    // An offline till whose clock was out (docs/PHARMACY.md §7.5): reports count the sale at reported_at.
                    TextEntry::make('clock_offset_seconds')
                        ->label("Till's clock")
                        ->formatStateUsing(fn ($state): string => static::clockOffset((int) $state))
                        ->visible(fn (Sale $record): bool => ($record->clock_offset_seconds ?? 0) !== 0),
                    TextEntry::make('reported_at')
                        ->label('Counted in reports at')
                        ->dateTime()
                        ->visible(fn (Sale $record): bool => $record->reported_at !== null && ! $record->reported_at->equalTo($record->sold_at)),
                ]),
            Section::make(fn (Sale $record): string => $record->synced_at === null ? 'Sale review' : 'Offline sale review')
                ->description(fn (Sale $record): string => $record->synced_at === null
                    ? 'This sale was recorded as the customer paid, but needs a check. See the points below.'
                    : 'This sale was made while the till was offline and recorded as the customer paid. Check the points below.')
                ->visible(fn (Sale $record): bool => $record->needs_review)
                ->columns(3)
                ->schema([
                    TextEntry::make('review_reasons')
                        ->label('What to check')
                        ->columnSpanFull()
                        ->bulleted()
                        ->getStateUsing(fn (Sale $record): array => array_map(
                            fn (array $reason): string => (string) ($reason['message'] ?? $reason['type'] ?? ''),
                            $record->review_reasons ?? [],
                        )),
                    TextEntry::make('synced_at')->label('Reached the server')->dateTime(),
                    TextEntry::make('reviewer.name')->label('Reviewed by')->placeholder('Not reviewed yet'),
                    TextEntry::make('reviewed_at')->label('Reviewed')->dateTime()->placeholder('-'),
                ]),
            Section::make('Items')->schema([
                RepeatableEntry::make('items')
                    ->hiddenLabel()
                    ->columns(6)
                    ->schema([
                        TextEntry::make('description')->columnSpan(2),
                        TextEntry::make('sku')->label('SKU'),
                        TextEntry::make('location.name')
                            ->label('From')
                            ->visible(fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('multi_location')),
                        TextEntry::make('quantity')->formatStateUsing(fn ($state): string => rtrim(rtrim(number_format((float) $state, 3), '0'), '.'))
                            ->suffix(fn ($record): ?string => (float) $record->returned_quantity > 0 ? ' ('.rtrim(rtrim(number_format((float) $record->returned_quantity, 3), '0'), '.').' returned)' : null),
                        TextEntry::make('unit_price')->label('Unit price')->formatStateUsing($money),
                        TextEntry::make('line_total')->label('Line total')->formatStateUsing($money),
                        // Charged something other than the price list: an OPD share or a price typed at the till.
                        TextEntry::make('charged')
                            ->label('Charged')
                            ->state(fn (SaleItem $record): string => static::chargedNote($record))
                            ->visible(fn (SaleItem $record): bool => static::chargedNote($record) !== '')
                            ->columnSpan(2),
                        TextEntry::make('drug_schedule')
                            ->label('Schedule')
                            ->badge()
                            ->formatStateUsing(fn (DrugSchedule $state): string => $state->label())
                            ->color(fn (DrugSchedule $state): string => $state === DrugSchedule::Otc ? 'gray' : 'warning')
                            ->visible(fn (SaleItem $record): bool => $record->drug_schedule !== null),
                        TextEntry::make('prescriber')
                            ->visible(fn (SaleItem $record): bool => filled($record->prescriber)),
                        TextEntry::make('prescription_ref')
                            ->label('Rx ref')
                            ->visible(fn (SaleItem $record): bool => filled($record->prescription_ref)),
                        // Batch-tracked lines: the batches taken, as sold.
                        TextEntry::make('batches_sold')
                            ->label('Batches')
                            ->state(fn (SaleItem $record): array => $record->batches->map(fn (SaleItemBatch $batch): string => $batch->label()
                                .' x '.Quantity::format((float) $batch->quantity)
                                .((float) $batch->returned_quantity > 0 ? ' ('.Quantity::format((float) $batch->returned_quantity).' returned)' : ''))->all())
                            ->bulleted()
                            ->visible(fn (SaleItem $record): bool => $record->batches->isNotEmpty())
                            ->columnSpanFull(),
                    ]),
            ]),
            // What came back (docs/PHARMACY.md §9, branch 9): batch and expiry as returned, and whether it was restocked.
            Section::make('Returns')
                ->visible(fn (Sale $record): bool => $record->returns->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('returns')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('return_no')->label('Refund no.'),
                            TextEntry::make('returned_at')->label('Returned')->dateTime(),
                            TextEntry::make('user.name')->label('By'),
                            TextEntry::make('reason_code')->label('Reason')->formatStateUsing(fn ($state): string => $state->label()),
                            TextEntry::make('refund_total')->label('Refund')->formatStateUsing($money),
                            TextEntry::make('refund_method')->label('Refunded by')
                                ->formatStateUsing(fn ($state): string => ucwords(str_replace('_', ' ', $state->value)))
                                ->suffix(fn (SaleReturn $record): ?string => filled($record->refund_reference) ? ' · ref '.$record->refund_reference : null),
                            TextEntry::make('note')->placeholder('-')->columnSpan(2),
                            TextEntry::make('lines')
                                ->label('Lines')
                                ->state(fn (SaleReturn $record): array => $record->items->map(fn (SaleReturnItem $line): string => static::returnLine($line))->all())
                                ->bulleted()
                                ->columnSpanFull(),
                        ]),
                ]),
            Section::make('Payments')->schema([
                RepeatableEntry::make('payments')
                    ->hiddenLabel()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('method')->formatStateUsing(fn ($state): string => ucwords(str_replace('_', ' ', $state->value))),
                        TextEntry::make('amount')->formatStateUsing($money),
                        TextEntry::make('reference')->visible(fn (Payment $record): bool => filled($record->reference)),
                    ]),
            ]),
        ]);
    }

    /**
     * Why a line's price differs from the price list, or '' when it does
     * not: an OPD medicine's share of the Medicines amount, or a price
     * typed at the till (list_price is set only then).
     */
    public static function chargedNote(SaleItem $item): string
    {
        $list = $item->list_price === null ? 'no list price' : 'list price '.ShopMoney::format($item->list_price);

        if ($item->is_opd_medicine) {
            return 'Share of the Medicines amount ('.$list.')';
        }

        if ($item->list_price !== null && $item->list_price !== $item->unit_price) {
            return 'Price changed at the till ('.$list.')';
        }

        return '';
    }

    /**
     * "Panadol 500 mg Tablets: 10 Tablet, refund LKR 60.00, back in stock (batch P1 · exp 01/2027 x 10)".
     */
    public static function returnLine(SaleReturnItem $line): string
    {
        $item = $line->saleItem;
        $quantity = Quantity::isWhole((float) $line->quantity) || $item->base_unit_name === null
            ? Quantity::format((float) $line->quantity).' '.$item->unit_name
            : Quantity::format((float) $line->base_quantity).' '.$item->base_unit_name;
        $batches = collect($line->batches ?? [])->map(fn (array $batch): string => ($batch['batch_no'] === '' ? 'unallocated' : $batch['batch_no'])
            .(filled($batch['expiry_date'] ?? null) ? ' · exp '.date('m/Y', (int) strtotime((string) $batch['expiry_date'])) : '')
            .' x '.Quantity::format((float) $batch['quantity']))->implode(', ');

        return $item->description.': '.trim($quantity).', refund '.ShopMoney::format($line->refund_amount)
            .($line->restocked ? ', back in stock' : ', not restocked')
            .($batches === '' ? '' : " ({$batches})");
    }

    /** "12 minutes ahead", "3 hours behind": the till's clock against the server's at upload. */
    public static function clockOffset(int $seconds): string
    {
        $minutes = (int) round(abs($seconds) / 60);
        $amount = match (true) {
            abs($seconds) < 60 => abs($seconds).' '.str('second')->plural(abs($seconds)),
            $minutes < 120 => $minutes.' '.str('minute')->plural($minutes),
            $minutes < 2 * 24 * 60 => round($minutes / 60, 1).' hours',
            default => round($minutes / (24 * 60), 1).' days',
        };

        return $amount.' '.($seconds > 0 ? 'ahead' : 'behind');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSales::route('/'),
            'view' => ViewSale::route('/{record}'),
        ];
    }
}
