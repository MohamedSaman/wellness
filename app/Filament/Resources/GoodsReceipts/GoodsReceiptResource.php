<?php

namespace App\Filament\Resources\GoodsReceipts;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PayableStatus;
use App\Exceptions\PosException;
use App\Filament\Resources\GoodsReceipts\Pages\CreateGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Pages\EditGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Pages\ListGoodsReceipts;
use App\Filament\Resources\GoodsReceipts\Pages\ViewGoodsReceipt;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Support\PayablesForm;
use App\Filament\Support\PurchasingForm;
use App\Filament\Support\ShopMoney;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Supplier;
use App\Models\SupplierAllocation;
use App\Services\Data\GoodsReceiptData;
use App\Services\Data\ReceiptLine;
use App\Services\SupplierPayablesService;
use App\Support\CurrentShop;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitCost;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
 * Goods received notes (purchasing module), with or without a purchase
 * order. A GRN is saved as a draft, checked, then posted: the stock comes
 * in (packs converted to base units, into each line's batch and expiry for
 * batch-tracked items), the location's average cost moves to the lines'
 * effective cost (free goods and discounts included), and it is numbered.
 * Posted GRNs are read-only; a mistake is put right with Reverse.
 * Entering and posting needs purchasing.receive; reversing needs
 * purchasing.manage. Everything is enforced in GoodsReceiptService.
 */
class GoodsReceiptResource extends PermissionResource
{
    protected static ?string $model = GoodsReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Goods received';

    protected static ?string $modelLabel = 'goods received note';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('purchasing') ?? false) && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        $batchLine = fn (Get $get): bool => StockTransferResource::tracksBatches($get('variant_id'));

        return $schema->components([
            Section::make()->columnSpanFull()
                ->columns(3)
                ->schema([
                    Hidden::make('purchase_order_id'),
                    PurchasingForm::supplierField()->disabled(fn (Get $get): bool => filled($get('purchase_order_id')))->dehydrated()->live(),
                    PurchasingForm::locationField('Received at', receiving: true),
                    DatePicker::make('received_on')->label('Received on')->default(now()->toDateString())->maxDate(now())->required()->live(),
                    TextInput::make('supplier_invoice_no')->label('Supplier invoice no.')->maxLength(50),
                    DatePicker::make('supplier_invoice_date')
                        ->label('Invoice date')
                        ->maxDate(now())
                        ->live()
                        ->helperText(fn (Get $get): ?string => PayablesForm::canSee() ? static::dueHint($get) : null),
                    TextInput::make('purchase_order')
                        ->label('Against order')
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (Get $get): bool => filled($get('purchase_order_id'))),
                ]),
            Section::make('Items')->columnSpanFull()
                ->description('Enter what the invoice says, in the unit it came in (boxes, strips or loose). Free goods lower the cost of every unit on the line, and tax (VAT) on the invoice adds to it. Medicines need the batch number and the expiry printed on the pack (03/2027 or a full date).')
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->minItems(1)
                        ->maxItems(200)
                        ->default([[]])
                        ->columns(PurchasingForm::lineGrid())
                        ->addActionLabel('Add item')
                        ->schema([
                            Hidden::make('purchase_order_item_id'),
                            PurchasingForm::pickedUnitField(),
                            PurchasingForm::itemField(creates: true)
                                ->afterStateUpdated(function (mixed $state, mixed $old, Get $get, Set $set): void {
                                    if ((string) $state === (string) $old) {
                                        return;
                                    }

                                    PurchasingForm::resetLine($set, $get);
                                    $set('purchase_order_item_id', null);
                                    $set('unit_cost', null);
                                    PurchasingForm::suggestCost($get, $set);
                                })
                                ->columnSpan(PurchasingForm::span(8, 6)),
                            PurchasingForm::unitField()
                                ->afterStateUpdated(function (Get $get, Set $set): void {
                                    $set('unit_cost', null);
                                    PurchasingForm::suggestCost($get, $set);
                                })
                                ->columnSpan(PurchasingForm::span(4, 2)),
                            TextInput::make('quantity')->label('Paid quantity')->numeric()->required()->minValue(0)->step(0.001)->default(1)->live(debounce: 400)->columnSpan(PurchasingForm::span(2, 2)),
                            TextInput::make('free_quantity')->label('Free')->numeric()->minValue(0)->step(0.001)->placeholder('0')->live(debounce: 400)->columnSpan(PurchasingForm::span(2, 2)),
                            PurchasingForm::moneyField('unit_cost', 'Cost per unit')
                                ->columnSpan(PurchasingForm::span(4, 3))
                                ->live(debounce: 400)
                                ->helperText(fn (Get $get): ?string => PurchasingForm::lineHint($get, receipt: true)),
                            PurchasingForm::moneyField('discount', 'Line discount')->placeholder('0')->live(debounce: 400)->columnSpan(PurchasingForm::span(4, 3)),
                            PurchasingForm::moneyField('tax', 'Line tax')->placeholder('0')->live(debounce: 400)->columnSpan(PurchasingForm::span(3, 3)),
                            TextInput::make('batch_no')->label('Batch no.')->maxLength(50)->visible($batchLine)->columnSpan(PurchasingForm::span(3, 3)),
                            TextInput::make('expiry')->label('Expiry')->placeholder('03/2027')->maxLength(10)->visible($batchLine)->columnSpan(PurchasingForm::span(3, 3)),
                            Toggle::make('accept_short_expiry')
                                ->label('Accept short expiry')
                                ->helperText('Owner only.')
                                ->inline(false)
                                ->columnSpan(PurchasingForm::span(3, 3))
                                ->visible(fn (Get $get): bool => $batchLine($get) && (PurchasingForm::user()?->can('batches.manage') ?? false)),
                        ]),
                ]),
            Section::make()->columnSpanFull()
                ->columns(2)
                ->schema([
                    PurchasingForm::moneyField('invoice_discount', 'Invoice discount')
                        ->placeholder('0')
                        ->live(debounce: 400),
                    PurchasingForm::moneyField('invoice_tax', 'Invoice tax (VAT)')
                        ->placeholder('0')
                        ->live(debounce: 400)
                        ->helperText('Tax on the whole invoice, when it is not entered on the lines. It is part of the cost: shared over the lines by value, after discounts.'),
                    TextEntry::make('totals')
                        ->hiddenLabel()
                        ->state(fn (Get $get): string => static::totalsHint($get))
                        ->columnSpanFull(),
                    Textarea::make('notes')->rows(2)->maxLength(1000)->columnSpanFull(),
                ]),
        ]);
    }

    /** "Lines LKR 1,000.00 after line discounts and tax; total LKR 1,170.00 after the invoice discount and tax." */
    public static function totalsHint(Get $get): string
    {
        $lines = 0;

        foreach ($get('lines') ?? [] as $line) {
            $lines += Money::multiply(PurchasingForm::toMinor($line['unit_cost'] ?? null), (float) ($line['quantity'] ?? 0))
                - PurchasingForm::toMinor($line['discount'] ?? null)
                + PurchasingForm::toMinor($line['tax'] ?? null);
        }

        $total = $lines - PurchasingForm::toMinor($get('invoice_discount')) + PurchasingForm::toMinor($get('invoice_tax'));

        return 'Lines '.ShopMoney::format($lines).' after line discounts and tax; total '.ShopMoney::format($total).' after the invoice discount and tax. Both are shared over the lines by value.';
    }

    /** @param  array<string, mixed>  $data */
    public static function dataFrom(array $data): GoodsReceiptData
    {
        return new GoodsReceiptData(
            supplierId: (int) $data['supplier_id'],
            locationId: (int) PurchasingForm::location($data['location_id'] ?? null)->getKey(),
            lines: array_values(array_map(fn (array $line): ReceiptLine => new ReceiptLine(
                (int) $line['variant_id'],
                (float) ($line['quantity'] ?? 0),
                filled($line['unit_id'] ?? null) ? (int) $line['unit_id'] : null,
                (float) ($line['free_quantity'] ?? 0),
                PurchasingForm::toMinor($line['unit_cost'] ?? null),
                PurchasingForm::toMinor($line['discount'] ?? null),
                $line['batch_no'] ?? null,
                $line['expiry'] ?? null,
                (bool) ($line['accept_short_expiry'] ?? false),
                filled($line['purchase_order_item_id'] ?? null) ? (int) $line['purchase_order_item_id'] : null,
                PurchasingForm::toMinor($line['tax'] ?? null),
            ), $data['lines'] ?? [])),
            receivedOn: $data['received_on'] ?? null,
            supplierInvoiceNo: $data['supplier_invoice_no'] ?? null,
            supplierInvoiceDate: $data['supplier_invoice_date'] ?? null,
            invoiceDiscount: PurchasingForm::toMinor($data['invoice_discount'] ?? null),
            notes: $data['notes'] ?? null,
            purchaseOrderId: filled($data['purchase_order_id'] ?? null) ? (int) $data['purchase_order_id'] : null,
            invoiceTax: PurchasingForm::toMinor($data['invoice_tax'] ?? null),
        );
    }

    /**
     * A stored line as form state.
     *
     * @return array<string, mixed>
     */
    public static function formLine(GoodsReceiptItem $item): array
    {
        return [
            'purchase_order_item_id' => $item->purchase_order_item_id,
            'variant_id' => $item->product_variant_id,
            'unit_id' => $item->variant_unit_id,
            'quantity' => (float) $item->quantity,
            'free_quantity' => (float) $item->free_quantity ?: null,
            'unit_cost' => PurchasingForm::toInput($item->unit_cost),
            'discount' => $item->discount === 0 ? null : PurchasingForm::toInput($item->discount),
            'tax' => $item->tax === 0 ? null : PurchasingForm::toInput($item->tax),
            'batch_no' => $item->batch_no,
            'expiry' => PurchasingForm::expiryInput($item->expiry_date),
            'accept_short_expiry' => $item->accept_short_expiry,
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['supplier', 'location', 'purchaseOrder', 'reversalOf', 'reversal', 'payable']))
            ->columns([
                TextColumn::make('grn_number')
                    ->label('GRN')
                    ->state(fn (GoodsReceipt $record): string => $record->label())
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (GoodsReceipt $record): ?string => match (true) {
                        $record->isReversal() => 'Reverses '.$record->reversalOf?->grn_number,
                        $record->reversal !== null => 'Reversed by '.$record->reversal->grn_number,
                        default => null,
                    }),
                TextColumn::make('received_on')->label('Received')->date()->sortable(),
                TextColumn::make('supplier.name')->label('Supplier')->searchable(),
                TextColumn::make('supplier_invoice_no')->label('Invoice')->placeholder('-')->searchable(),
                TextColumn::make('location.name')->label('Location')->visible(fn (): bool => PurchasingForm::multiLocation()),
                TextColumn::make('purchaseOrder.po_number')->label('Order')->placeholder('-')->toggleable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (GoodsReceiptStatus $state): string => $state->label())->color(fn (GoodsReceiptStatus $state): string => $state->color()),
                TextColumn::make('total')->formatStateUsing(fn (int $state): ?string => ShopMoney::format($state)),
                static::paymentColumn(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(GoodsReceiptStatus::cases())->mapWithKeys(fn (GoodsReceiptStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('supplier_id')->label('Supplier')->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('payment')
                    ->label('Payment')
                    ->options(PayableStatus::options())
                    ->visible(fn (): bool => PayablesForm::canSee())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('payable', fn (Builder $payable) => $payable->where('status', $data['value']))),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (GoodsReceipt $record): string => static::getUrl('view', ['record' => $record]));
    }

    /** Paid, part paid or unpaid, with what is outstanding and when it is due (payables). */
    public static function paymentColumn(): TextColumn
    {
        return TextColumn::make('payment')
            ->label('Payment')
            ->badge()
            ->state(fn (GoodsReceipt $record): ?string => $record->payable?->status->label())
            ->color(fn (GoodsReceipt $record): ?string => $record->payable?->status->color())
            ->description(fn (GoodsReceipt $record): ?string => $record->payable === null || ! $record->payable->status->isOpen()
                ? null
                : ShopMoney::format($record->payable->outstanding()).' due '.$record->payable->due_on->format('d/m/Y'))
            ->placeholder('-')
            ->visible(fn (): bool => PayablesForm::canSee());
    }

    /** "Due 17/11/2026 (30 days' terms)": what the GRN will be due on when posted. */
    public static function dueHint(Get $get): ?string
    {
        $supplier = filled($get('supplier_id')) ? Supplier::query()->find((int) $get('supplier_id')) : null;
        $from = $get('supplier_invoice_date') ?: $get('received_on');

        if ($supplier === null || blank($from)) {
            return null;
        }

        try {
            $due = app(SupplierPayablesService::class)->dueOn($supplier, (string) $from);
        } catch (PosException) {
            return null;
        }

        return 'Due '.$due->format('d/m/Y').' ('.(int) ($supplier->payment_terms_days ?? 0).' days\' terms from the '.(filled($get('supplier_invoice_date')) ? 'invoice date' : 'date received').').';
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn ($state): ?string => ShopMoney::format((int) $state);
        $quantity = fn ($state): string => Quantity::format((float) $state);

        return $schema->components([
            Section::make()->columnSpanFull()
                ->columns(4)
                ->schema([
                    TextEntry::make('status')->badge()->formatStateUsing(fn (GoodsReceiptStatus $state): string => $state->label())->color(fn (GoodsReceiptStatus $state): string => $state->color()),
                    TextEntry::make('supplier.name')->label('Supplier'),
                    TextEntry::make('location.name')->label('Received at')->visible(fn (): bool => PurchasingForm::multiLocation()),
                    TextEntry::make('received_on')->label('Received on')->date(),
                    TextEntry::make('supplier_invoice_no')->label('Supplier invoice')->placeholder('-'),
                    TextEntry::make('supplier_invoice_date')->label('Invoice date')->date()->placeholder('-'),
                    TextEntry::make('purchaseOrder.po_number')->label('Against order')->placeholder('-'),
                    TextEntry::make('posted')
                        ->label('Posted')
                        ->state(fn (GoodsReceipt $record): ?string => $record->posted_at === null ? null : $record->posted_at->format('d/m/Y H:i').' by '.($record->poster->name ?? '-'))
                        ->placeholder('Not yet: a draft moves no stock'),
                    TextEntry::make('reversal_note')
                        ->label('Reversal')
                        ->state(fn (GoodsReceipt $record): ?string => match (true) {
                            $record->isReversal() => 'Reverses '.$record->reversalOf?->grn_number,
                            $record->reversal !== null => 'Reversed by '.$record->reversal->grn_number,
                            default => null,
                        })
                        ->color('danger')
                        ->visible(fn (GoodsReceipt $record): bool => $record->isReversal() || $record->reversal !== null),
                    TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
                ]),
            Section::make('Payment')->columnSpanFull()
                ->columns(['default' => 2, 'lg' => 5])
                ->visible(fn (GoodsReceipt $record): bool => $record->payable !== null && PayablesForm::canSee())
                ->schema([
                    TextEntry::make('payable.status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (PayableStatus $state): string => $state->label())
                        ->color(fn (PayableStatus $state): string => $state->color()),
                    TextEntry::make('payable.due_on')
                        ->label('Due on')
                        ->date()
                        ->color(fn (GoodsReceipt $record): ?string => ($record->payable?->status->isOpen() ?? false) && $record->payable->daysOverdue(app(SupplierPayablesService::class)->today()) > 0 ? 'danger' : null)
                        ->helperText(fn (GoodsReceipt $record): ?string => ($record->payable?->status->isOpen() ?? false) && ($late = $record->payable->daysOverdue(app(SupplierPayablesService::class)->today())) > 0 ? $late.' days overdue' : null),
                    TextEntry::make('payable.amount')->label('Owed')->formatStateUsing($money),
                    TextEntry::make('payable.allocated')->label('Paid and credited')->formatStateUsing($money),
                    TextEntry::make('outstanding')
                        ->label('Outstanding')
                        ->weight('bold')
                        ->state(fn (GoodsReceipt $record): ?string => ShopMoney::format($record->payable?->outstanding())),
                    TextEntry::make('paid_by')
                        ->label('Paid by')
                        ->columnSpanFull()
                        ->placeholder('Nothing yet')
                        ->state(fn (GoodsReceipt $record): ?string => static::paidBy($record)),
                ]),
            Section::make('Items')->columnSpanFull()->schema([
                RepeatableEntry::make('items')
                    ->hiddenLabel()
                    ->columns(6)
                    ->schema([
                        TextEntry::make('item')
                            ->label('Item')
                            ->state(fn (GoodsReceiptItem $record): ?string => StockTransferResource::itemLabel($record->variant))
                            ->columnSpan(2),
                        TextEntry::make('quantity')
                            ->label('Quantity')
                            ->state(fn (GoodsReceiptItem $record): string => Quantity::format((float) $record->quantity).' '.$record->unit_name
                                .((float) $record->free_quantity != 0 ? ' + '.Quantity::format((float) $record->free_quantity).' free' : '')),
                        TextEntry::make('base_quantity')->label('Base units')->formatStateUsing($quantity)->suffix(fn (GoodsReceiptItem $record): string => ' '.$record->variant->product->unit),
                        TextEntry::make('amounts')
                            ->label('Cost')
                            ->state(fn (GoodsReceiptItem $record): string => ShopMoney::format($record->unit_cost).' each'
                                .($record->discount != 0 ? ' − '.ShopMoney::format(abs($record->discount)) : '')
                                .($record->invoice_discount_share != 0 ? ' − '.ShopMoney::format(abs($record->invoice_discount_share)).' invoice' : '')
                                .($record->tax + $record->invoice_tax_share != 0 ? ' + '.ShopMoney::format(abs($record->tax + $record->invoice_tax_share)).' tax' : '')),
                        TextEntry::make('line_total')->label('Line total')->formatStateUsing($money),
                        TextEntry::make('base_unit_cost')
                            ->label('Cost per base unit')
                            ->state(fn (GoodsReceiptItem $record): ?string => $record->base_unit_cost === null ? null : ShopMoney::format(UnitCost::toMinor($record->base_unit_cost)).' a '.$record->variant->product->unit)
                            ->tooltip('Line total over paid and free base units: what the batch and the average cost take.'),
                        TextEntry::make('batch')
                            ->label('Batch')
                            ->state(fn (GoodsReceiptItem $record): ?string => $record->batchLabel())
                            ->placeholder('-')
                            ->visible(fn (GoodsReceiptItem $record): bool => $record->batch_no !== null || $record->variant->product->tracksBatches()),
                    ]),
            ]),
            Section::make()->columnSpanFull()
                ->columns(['default' => 2, 'lg' => 6])
                ->schema([
                    TextEntry::make('subtotal')->formatStateUsing($money),
                    TextEntry::make('line_discount_total')->label('Line discounts')->formatStateUsing($money),
                    TextEntry::make('invoice_discount')->label('Invoice discount')->formatStateUsing($money),
                    TextEntry::make('line_tax_total')->label('Line tax')->formatStateUsing($money),
                    TextEntry::make('invoice_tax')->label('Invoice tax')->formatStateUsing($money),
                    TextEntry::make('total')->weight('bold')->formatStateUsing($money),
                ]),
        ]);
    }

    /** "Payment 17/10/2026 TRF 1: LKR 400.00; Return SR-000002: LKR 50.00". */
    public static function paidBy(GoodsReceipt $receipt): ?string
    {
        $allocations = $receipt->payable?->allocations()
            ->whereNull('reversal_of_id')
            ->whereDoesntHave('reversal')
            ->with(['credit.payment', 'credit.supplierReturn'])
            ->orderBy('id')
            ->get();

        if ($allocations === null || $allocations->isEmpty()) {
            return null;
        }

        return $allocations->map(fn (SupplierAllocation $allocation): string => $allocation->credit->label().': '.ShopMoney::format($allocation->amount))->implode('; ');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoodsReceipts::route('/'),
            'create' => CreateGoodsReceipt::route('/create'),
            'view' => ViewGoodsReceipt::route('/{record}'),
            'edit' => EditGoodsReceipt::route('/{record}/edit'),
        ];
    }
}
