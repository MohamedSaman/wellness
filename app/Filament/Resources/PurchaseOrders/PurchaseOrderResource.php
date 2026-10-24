<?php

namespace App\Filament\Resources\PurchaseOrders;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Support\PurchasingForm;
use App\Filament\Support\ShopMoney;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Data\PurchaseLine;
use App\Support\CurrentShop;
use App\Support\Quantity;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
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
 * Purchase orders (purchasing module, optional): lines in any unit the
 * item is bought in, at the expected cost. A draft is edited; Send marks
 * it sent (print it for the supplier); goods are then received against it
 * in one delivery or several, or it is cancelled. Making and changing
 * orders needs purchasing.manage; whoever receives goods can read them.
 */
class PurchaseOrderResource extends PermissionResource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Purchase orders';

    protected static ?string $modelLabel = 'purchase order';

    protected static ?int $navigationSort = 2;

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
                    PurchasingForm::supplierField(),
                    PurchasingForm::locationField('Deliver to', receiving: true),
                    DatePicker::make('expected_on')->label('Expected on'),
                    Textarea::make('notes')->rows(2)->maxLength(1000)->columnSpanFull()->placeholder('Printed on the order: delivery instructions, contact ...'),
                ]),
            Section::make('Items')->columnSpanFull()
                ->description('Order in any unit the item is bought in: boxes, strips or loose units. The cost is what you expect to pay for one of that unit.')
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->minItems(1)
                        ->maxItems(200)
                        ->default([[]])
                        ->columns(PurchasingForm::lineGrid())
                        ->addActionLabel('Add item')
                        ->schema([
                            PurchasingForm::pickedUnitField(),
                            PurchasingForm::itemField(creates: true)
                                ->afterStateUpdated(function (mixed $state, mixed $old, Get $get, Set $set): void {
                                    if ((string) $state === (string) $old) {
                                        return;
                                    }

                                    PurchasingForm::resetLine($set, $get);
                                    $set('unit_cost', null);
                                    PurchasingForm::suggestCost($get, $set);
                                })
                                ->columnSpan(PurchasingForm::span(8, 5)),
                            PurchasingForm::unitField()
                                ->afterStateUpdated(function (Get $get, Set $set): void {
                                    $set('unit_cost', null);
                                    PurchasingForm::suggestCost($get, $set);
                                })
                                ->columnSpan(PurchasingForm::span(4, 2)),
                            TextInput::make('quantity')->numeric()->required()->minValue(0.001)->step(0.001)->live(debounce: 400)->columnSpan(PurchasingForm::span(4, 2)),
                            PurchasingForm::moneyField('unit_cost', 'Expected cost')
                                ->columnSpan(PurchasingForm::span(4, 3))
                                ->live(debounce: 400)
                                ->helperText(fn (Get $get): ?string => PurchasingForm::lineHint($get, receipt: false)),
                        ]),
                ]),
        ]);
    }

    /**
     * The repeater's rows as service lines.
     *
     * @param  array<string, mixed>  $data
     * @return list<PurchaseLine>
     */
    public static function linesFrom(array $data): array
    {
        return array_values(array_map(fn (array $line): PurchaseLine => new PurchaseLine(
            (int) $line['variant_id'],
            (float) ($line['quantity'] ?? 0),
            filled($line['unit_id'] ?? null) ? (int) $line['unit_id'] : null,
            PurchasingForm::toMinor($line['unit_cost'] ?? null),
        ), $data['lines'] ?? []));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['supplier', 'location'])->withCount('items'))
            ->columns([
                TextColumn::make('po_number')->label('Order')->weight('bold')->searchable(),
                TextColumn::make('created_at')->label('Made')->date()->sortable(),
                TextColumn::make('supplier.name')->label('Supplier')->searchable(),
                TextColumn::make('location.name')->label('Deliver to')->visible(fn (): bool => PurchasingForm::multiLocation()),
                TextColumn::make('status')->badge()->formatStateUsing(fn (PurchaseOrderStatus $state): string => $state->label())->color(fn (PurchaseOrderStatus $state): string => $state->color()),
                TextColumn::make('expected_on')->label('Expected')->date()->placeholder('-')->sortable(),
                TextColumn::make('items_count')->label('Lines'),
                TextColumn::make('total')->label('Expected total')->formatStateUsing(fn (int $state): ?string => ShopMoney::format($state)),
            ])
            ->filters([
                SelectFilter::make('status')->options(PurchaseOrderStatus::options()),
                SelectFilter::make('supplier_id')->label('Supplier')->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (PurchaseOrder $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        $quantity = fn ($state): string => Quantity::format((float) $state);

        return $schema->components([
            Section::make()->columnSpanFull()
                ->columns(4)
                ->schema([
                    TextEntry::make('status')->badge()->formatStateUsing(fn (PurchaseOrderStatus $state): string => $state->label())->color(fn (PurchaseOrderStatus $state): string => $state->color()),
                    TextEntry::make('supplier.name')->label('Supplier'),
                    TextEntry::make('location.name')->label('Deliver to')->visible(fn (): bool => PurchasingForm::multiLocation()),
                    TextEntry::make('expected_on')->label('Expected on')->date()->placeholder('-'),
                    TextEntry::make('total')->label('Expected total')->formatStateUsing(fn (int $state): ?string => ShopMoney::format($state)),
                    TextEntry::make('sent_at')->label('Sent')->dateTime()->placeholder('Not yet'),
                    TextEntry::make('user.name')->label('Made by')->placeholder('-'),
                    TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
                ]),
            Section::make('Items')->columnSpanFull()->schema([
                RepeatableEntry::make('items')
                    ->hiddenLabel()
                    ->columns(5)
                    ->schema([
                        TextEntry::make('item')
                            ->label('Item')
                            ->state(fn (PurchaseOrderItem $record): ?string => StockTransferResource::itemLabel($record->variant))
                            ->columnSpan(2),
                        TextEntry::make('quantity')->label('Ordered')->formatStateUsing($quantity)->suffix(fn (PurchaseOrderItem $record): string => ' '.$record->unit_name),
                        TextEntry::make('received_base_quantity')
                            ->label('Received')
                            ->state(fn (PurchaseOrderItem $record): string => Quantity::format((float) $record->received_base_quantity).' of '.Quantity::format((float) $record->base_quantity).' '.$record->variant->product->unit),
                        TextEntry::make('line_total')
                            ->label('Expected')
                            ->state(fn (PurchaseOrderItem $record): string => ShopMoney::format($record->unit_cost).' each · '.ShopMoney::format($record->line_total)),
                    ]),
            ]),
            Section::make('Goods received')->columnSpanFull()
                ->visible(fn (PurchaseOrder $record): bool => $record->goodsReceipts->isNotEmpty())
                ->schema([
                    TextEntry::make('deliveries')
                        ->hiddenLabel()
                        ->state(fn (PurchaseOrder $record): array => $record->goodsReceipts
                            ->map(fn (GoodsReceipt $grn): string => $grn->label().' · '.$grn->received_on->format('d/m/Y').($grn->status === GoodsReceiptStatus::Draft ? ' (draft)' : '').($grn->isReversal() ? ' (reversal)' : ''))
                            ->all())
                        ->listWithLineBreaks()
                        ->bulleted(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'view' => ViewPurchaseOrder::route('/{record}'),
            'edit' => EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
