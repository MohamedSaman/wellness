<?php

namespace App\Filament\Resources\SupplierReturns;

use App\Enums\GoodsReceiptStatus;
use App\Enums\SupplierReturnReason;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Resources\SupplierReturns\Pages\CreateSupplierReturn;
use App\Filament\Resources\SupplierReturns\Pages\ListSupplierReturns;
use App\Filament\Resources\SupplierReturns\Pages\ViewSupplierReturn;
use App\Filament\Support\PurchasingForm;
use App\Filament\Support\ShopMoney;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnItem;
use App\Services\Data\SupplierReturnLine;
use App\Support\CurrentShop;
use App\Support\Quantity;
use App\Support\UnitCost;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
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
 * Stock sent back to a supplier (purchasing module): expired, near expiry,
 * damaged or recalled goods, picked batch by batch at one location (expired
 * and recalled batches included). Saving posts it: the stock leaves those
 * batches at their purchase cost and the credit lowers the supplier's
 * balance. Read-only afterwards, except for the supplier's credit note
 * number. Needs purchasing.manage (SupplierReturnService).
 */
class SupplierReturnResource extends PermissionResource
{
    protected static ?string $model = SupplierReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Supplier returns';

    protected static ?string $modelLabel = 'supplier return';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('purchasing') ?? false) && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()
                ->columns(3)
                ->schema([
                    PurchasingForm::supplierField()->live(),
                    PurchasingForm::locationField('From')->live(),
                    Select::make('goods_receipt_id')
                        ->label('From goods received note')
                        ->options(fn (Get $get): array => filled($get('supplier_id')) ? GoodsReceipt::query()
                            ->where('supplier_id', (int) $get('supplier_id'))
                            ->where('status', GoodsReceiptStatus::Posted)
                            ->whereNull('reversal_of_id')
                            ->whereDoesntHave('reversal')
                            ->latest('received_on')
                            ->limit(100)
                            ->get()
                            ->mapWithKeys(fn (GoodsReceipt $receipt): array => [$receipt->getKey() => $receipt->grn_number.' · '.$receipt->received_on->format('d/m/Y').($receipt->supplier_invoice_no === null ? '' : ' · invoice '.$receipt->supplier_invoice_no)])
                            ->all() : [])
                        ->searchable()
                        ->placeholder('Work it out from the batches')
                        ->helperText('The credit reduces what is owed on it. Left blank, it is the GRN that brought in the batches; if there are several, the credit stays on the supplier\'s account to allocate.'),
                    Select::make('reason')->options(SupplierReturnReason::options())->required()->native(false),
                    DatePicker::make('returned_on')->label('Returned on')->default(now()->toDateString())->maxDate(now())->required(),
                    TextInput::make('credit_note_no')->label('Supplier credit note no.')->maxLength(50)->helperText('Can be added later.'),
                    Textarea::make('notes')->rows(2)->maxLength(1000),
                ]),
            Section::make('Items')->columnSpanFull()
                ->description('Pick the batch each item goes back from (expired and recalled batches too). The credit is worked out at what the batch cost.')
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->minItems(1)
                        ->maxItems(200)
                        ->default([[]])
                        ->columns(PurchasingForm::lineGrid())
                        ->addActionLabel('Add item')
                        ->schema([
                            PurchasingForm::itemField()
                                ->afterStateUpdated(fn (mixed $state, mixed $old, Set $set) => (string) $state === (string) $old ? null : PurchasingForm::resetLine($set))
                                ->columnSpan(PurchasingForm::span(7, 7)),
                            Select::make('batch_id')
                                ->label('Batch')
                                ->options(fn (Get $get): array => StockTransferResource::batchOptions($get('variant_id'), PurchasingForm::location($get('../../location_id'))->getKey()))
                                ->required(fn (Get $get): bool => StockTransferResource::tracksBatches($get('variant_id')))
                                ->visible(fn (Get $get): bool => StockTransferResource::tracksBatches($get('variant_id')))
                                ->native(false)
                                ->columnSpan(PurchasingForm::span(5, 5)),
                            PurchasingForm::unitField(bought: false)->columnSpan(PurchasingForm::span(4, 4)),
                            TextInput::make('quantity')
                                ->columnSpan(PurchasingForm::span(4, 4))
                                ->numeric()
                                ->required()
                                ->minValue(0.001)
                                ->step(0.001)
                                ->helperText(fn (Get $get): ?string => StockTransferResource::onHandHint($get('variant_id'), PurchasingForm::location($get('../../location_id'))->getKey())),
                        ]),
                ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<SupplierReturnLine>
     */
    public static function linesFrom(array $data): array
    {
        return array_values(array_map(fn (array $line): SupplierReturnLine => new SupplierReturnLine(
            (int) $line['variant_id'],
            (float) ($line['quantity'] ?? 0),
            filled($line['unit_id'] ?? null) ? (int) $line['unit_id'] : null,
            filled($line['batch_id'] ?? null) ? (int) $line['batch_id'] : null,
        ), $data['lines'] ?? []));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['supplier', 'location']))
            ->columns([
                TextColumn::make('return_number')->label('Return')->weight('bold')->searchable(),
                TextColumn::make('returned_on')->label('Returned')->date()->sortable(),
                TextColumn::make('supplier.name')->label('Supplier')->searchable(),
                TextColumn::make('location.name')->label('From')->visible(fn (): bool => PurchasingForm::multiLocation()),
                TextColumn::make('reason')->formatStateUsing(fn (SupplierReturnReason $state): string => $state->label()),
                TextColumn::make('credit_note_no')->label('Credit note')->placeholder('-'),
                TextColumn::make('total')->label('Credit')->formatStateUsing(fn (int $state): ?string => ShopMoney::format($state)),
            ])
            ->filters([
                SelectFilter::make('reason')->options(SupplierReturnReason::options()),
                SelectFilter::make('supplier_id')->label('Supplier')->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (SupplierReturn $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()
                ->columns(4)
                ->schema([
                    TextEntry::make('supplier.name')->label('Supplier'),
                    TextEntry::make('location.name')->label('From')->visible(fn (): bool => PurchasingForm::multiLocation()),
                    TextEntry::make('reason')->formatStateUsing(fn (SupplierReturnReason $state): string => $state->label()),
                    TextEntry::make('returned_on')->label('Returned on')->date(),
                    TextEntry::make('credit_note_no')->label('Credit note')->placeholder('Not yet'),
                    TextEntry::make('goodsReceipt.grn_number')->label('From GRN')->placeholder('-'),
                    TextEntry::make('total')->label('Credit expected')->weight('bold')->formatStateUsing(fn (int $state): ?string => ShopMoney::format($state)),
                    TextEntry::make('user.name')->label('By')->placeholder('-'),
                    TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
                ]),
            Section::make('Items')->columnSpanFull()->schema([
                RepeatableEntry::make('items')
                    ->hiddenLabel()
                    ->columns(5)
                    ->schema([
                        TextEntry::make('item')
                            ->label('Item')
                            ->state(fn (SupplierReturnItem $record): ?string => StockTransferResource::itemLabel($record->variant))
                            ->columnSpan(2),
                        TextEntry::make('batch')->label('Batch')->state(fn (SupplierReturnItem $record): ?string => $record->batchLabel())->placeholder('-'),
                        TextEntry::make('quantity')
                            ->state(fn (SupplierReturnItem $record): string => Quantity::format((float) $record->quantity).' '.$record->unit_name
                                .((float) $record->unit_factor != 1.0 ? ' ('.Quantity::format((float) $record->base_quantity).' '.$record->variant->product->unit.')' : '')),
                        TextEntry::make('line_total')
                            ->label('Credit')
                            ->state(fn (SupplierReturnItem $record): string => ShopMoney::format($record->line_total).' ('.ShopMoney::format(UnitCost::toMinor($record->unit_cost)).' a '.$record->variant->product->unit.')'),
                    ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierReturns::route('/'),
            'create' => CreateSupplierReturn::route('/create'),
            'view' => ViewSupplierReturn::route('/{record}'),
        ];
    }
}
