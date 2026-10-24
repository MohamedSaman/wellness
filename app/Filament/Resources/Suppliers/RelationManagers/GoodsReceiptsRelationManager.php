<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Enums\GoodsReceiptStatus;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\ShopMoney;
use App\Models\GoodsReceipt;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The supplier's goods received notes (purchasing), newest first; each opens its own page. */
class GoodsReceiptsRelationManager extends RelationManager
{
    protected static string $relationship = 'goodsReceipts';

    protected static ?string $title = 'Goods received';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return SupplierResource::showsBalance();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('payable'))
            ->columns([
                TextColumn::make('grn_number')->label('GRN')->state(fn (GoodsReceipt $record): string => $record->label())->weight('bold'),
                TextColumn::make('received_on')->label('Received')->date()->sortable(),
                TextColumn::make('supplier_invoice_no')->label('Invoice')->placeholder('-'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (GoodsReceiptStatus $state): string => $state->label())->color(fn (GoodsReceiptStatus $state): string => $state->color()),
                TextColumn::make('total')->formatStateUsing(fn (int $state): ?string => ShopMoney::format($state)),
                GoodsReceiptResource::paymentColumn(),
            ])
            ->defaultSort('received_on', 'desc')
            // Someone who only reads payables sees the list, not the GRN's page.
            ->recordUrl(fn (GoodsReceipt $record): ?string => (auth()->user()?->can('view', $record) ?? false) ? GoodsReceiptResource::getUrl('view', ['record' => $record]) : null);
    }
}
