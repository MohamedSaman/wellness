<?php

namespace App\Filament\Resources\Suppliers;

use App\Exceptions\PosException;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\RelationManagers\AllocationsRelationManager;
use App\Filament\Resources\Suppliers\RelationManagers\GoodsReceiptsRelationManager;
use App\Filament\Resources\Suppliers\RelationManagers\PayablesRelationManager;
use App\Filament\Resources\Suppliers\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Suppliers\RelationManagers\SupplierReturnsRelationManager;
use App\Filament\Support\PayablesForm;
use App\Filament\Support\ShopMoney;
use App\Models\Supplier;
use App\Services\SupplierPayablesService;
use App\Services\SupplierService;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Suppliers (suppliers module): contact details, payment terms, and with
 * purchasing on, what the shop owes them (posted goods received less
 * returns and payments), invoice by invoice with due dates, with their
 * GRNs, returns, payments and allocations, and a printable statement.
 * Read with purchasing.manage or payables.view; changes go through
 * SupplierService and SupplierPayablesService (purchasing.manage) and are
 * audited; a supplier is deactivated, never deleted.
 */
class SupplierResource extends PermissionResource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('suppliers') ?? false) && parent::canAccess();
    }

    /** The balance and the documents behind it: purchasing on, and the user manages purchasing or reads payables. */
    public static function showsBalance(): bool
    {
        return PayablesForm::canSee();
    }

    /** Payments, opening balances, allocations: purchasing on, and purchasing.manage. */
    public static function managesPayables(): bool
    {
        return PayablesForm::canManage();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(150),
            TextInput::make('contact_name')->label('Contact person')->maxLength(150),
            TextInput::make('phone')->tel()->maxLength(50),
            TextInput::make('email')->email()->maxLength(150),
            TextInput::make('tax_number')->label('Tax / registration no.')->maxLength(50),
            TextInput::make('payment_terms_days')
                ->label('Payment terms (days)')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(365)
                ->placeholder('e.g. 30'),
            Textarea::make('address')->rows(2)->columnSpanFull(),
            Textarea::make('notes')->rows(2)->columnSpanFull()->placeholder('Delivery days, sales rep, account number ...'),
            Toggle::make('is_active')->label('Active')->default(true)->helperText('Inactive suppliers are not offered on new orders and deliveries.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => static::showsBalance() ? static::withBalance($query) : $query)
            ->columns([
                TextColumn::make('name')->weight('bold')->searchable()->sortable()->description(fn (Supplier $record): ?string => $record->contact_name),
                TextColumn::make('phone')->placeholder('-'),
                TextColumn::make('email')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment_terms_days')->label('Terms')->suffix(' days')->placeholder('-'),
                TextColumn::make('balance')
                    ->label('Balance owed')
                    ->state(fn (Supplier $record): int => static::balanceOf($record))
                    ->formatStateUsing(fn (int $state): ?string => ShopMoney::format($state))
                    ->color(fn (int $state): ?string => $state > 0 ? 'warning' : null)
                    ->visible(fn (): bool => static::showsBalance()),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active')->default(true),
            ])
            ->defaultSort('name')
            ->recordUrl(fn (Supplier $record): string => static::getUrl('view', ['record' => $record]));
    }

    /**
     * The four sums behind the balance, as columns of the supplier query.
     *
     * @param  Builder<Supplier>  $query
     * @return Builder<Supplier>
     */
    public static function withBalance(Builder $query): Builder
    {
        return $query
            ->withSum('openingBalances as opening_total', 'amount')
            ->withSum(['goodsReceipts as purchases_total' => fn (Builder $grns) => $grns->where('status', 'posted')], 'total')
            ->withSum('supplierReturns as returns_total', 'total')
            ->withSum('payments as payments_total', 'amount');
    }

    public static function balanceOf(Supplier $supplier): int
    {
        $sums = $supplier->getAttributes();

        return (int) ($sums['opening_total'] ?? 0) + (int) ($sums['purchases_total'] ?? 0) - (int) ($sums['returns_total'] ?? 0) - (int) ($sums['payments_total'] ?? 0);
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn (string $key): \Closure => fn (Supplier $record): ?string => ShopMoney::format(app(SupplierService::class)->balance($record)[$key]);
        $open = fn (string $key): \Closure => fn (Supplier $record): ?string => ShopMoney::format(app(SupplierPayablesService::class)->summary((int) $record->getKey())[$key]);

        return $schema->components([
            Section::make()->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('contact_name')->label('Contact person')->placeholder('-'),
                    TextEntry::make('phone')->placeholder('-'),
                    TextEntry::make('email')->placeholder('-'),
                    TextEntry::make('tax_number')->label('Tax / registration no.')->placeholder('-'),
                    TextEntry::make('payment_terms_days')->label('Payment terms')->suffix(' days')->placeholder('-'),
                    IconEntry::make('is_active')->label('Active')->boolean(),
                    TextEntry::make('address')->placeholder('-')->columnSpan(2),
                    TextEntry::make('notes')->placeholder('-'),
                ]),
            Section::make('Balance')->columnSpanFull()
                ->description('Opening balance and posted goods received, less returns and payments. Positive: the shop owes the supplier. It always equals the unpaid invoices less the credit to allocate.')
                ->columns(['default' => 2, 'lg' => 4])
                ->visible(fn (): bool => static::showsBalance())
                ->schema([
                    TextEntry::make('opening')
                        ->label('Opening balance')
                        ->state(fn (Supplier $record): ?string => ($entry = app(SupplierService::class)->openingBalance($record)) === null ? null : ShopMoney::format($entry->amount))
                        ->placeholder('None entered')
                        ->helperText(fn (Supplier $record): ?string => ($entry = app(SupplierService::class)->openingBalance($record)) === null
                            ? null
                            : 'As of '.$entry->as_of->format('d/m/Y').(blank($entry->note) ? '' : ': '.$entry->note)),
                    TextEntry::make('purchases')->label('Goods received')->state($money('purchases')),
                    TextEntry::make('returns')->label('Returns')->state($money('returns')),
                    TextEntry::make('payments')->label('Paid')->state($money('payments')),
                    TextEntry::make('balance')->label('Balance owed')->weight('bold')->state($money('balance')),
                    TextEntry::make('outstanding')
                        ->label('Unpaid invoices')
                        ->state($open('outstanding'))
                        ->helperText('Goods received and opening entries not yet paid.'),
                    TextEntry::make('overdue')
                        ->label('Overdue')
                        ->state($open('overdue'))
                        ->color(fn (Supplier $record): ?string => app(SupplierPayablesService::class)->summary((int) $record->getKey())['overdue'] > 0 ? 'danger' : null),
                    TextEntry::make('credit')
                        ->label('Credit to allocate')
                        ->state($open('credit'))
                        ->helperText('Paid or credited, not yet set against an invoice.'),
                ]),
        ]);
    }

    /**
     * Run a SupplierService call; a business-rule failure is shown and the form stays open.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function attempt(Action $action, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            $action->halt();

            throw $exception;
        }
    }

    public static function getRelations(): array
    {
        return [
            PayablesRelationManager::class,
            GoodsReceiptsRelationManager::class,
            SupplierReturnsRelationManager::class,
            PaymentsRelationManager::class,
            AllocationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'view' => ViewSupplier::route('/{record}'),
        ];
    }
}
